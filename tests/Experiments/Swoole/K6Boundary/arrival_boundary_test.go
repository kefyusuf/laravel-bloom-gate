package executor

import (
	"context"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/stretchr/testify/require"
	"gopkg.in/guregu/null.v3"

	"go.k6.io/k6/lib"
	"go.k6.io/k6/lib/types"
	"go.k6.io/k6/metrics"
)

// Clock/context controls are installed only by the test overlay. The executor,
// VU pool, runner and dropped-iteration accounting are upstream implementations.
type boundaryClock struct {
	elapsed           atomic.Int64
	stopAt            time.Duration
	cancel            context.CancelFunc
	cancelAfterTimer  time.Duration
	releaseAfterTimer time.Duration
	release           chan struct{}
	releaseOnce       sync.Once
	entered           chan struct{}
	enteredOnce       sync.Once
}

var boundaryControl *boundaryClock

type boundaryTimer struct{ C chan time.Time }

func boundaryNewTimer(time.Duration) *boundaryTimer {
	return &boundaryTimer{C: make(chan time.Time, 1)}
}

func (timer *boundaryTimer) Reset(delay time.Duration) bool {
	next := time.Duration(boundaryControl.elapsed.Load()) + delay
	if boundaryControl.entered != nil && next > 0 {
		select {
		case <-boundaryControl.entered:
		case <-time.After(5 * time.Second):
			panic("first offered slot did not enter the runner")
		}
	}
	boundaryControl.elapsed.Store(int64(next))
	if next >= boundaryControl.stopAt {
		boundaryControl.cancel()
		return false
	}
	timer.C <- time.Unix(0, int64(next))
	return false
}

func boundarySince(time.Time) time.Duration {
	return time.Duration(boundaryControl.elapsed.Load())
}

func boundaryAfterTimer() {
	if boundaryControl.release != nil && boundarySince(time.Time{}) >= boundaryControl.releaseAfterTimer {
		boundaryControl.releaseOnce.Do(func() { close(boundaryControl.release) })
	}
	if boundaryControl.cancelAfterTimer > 0 && boundarySince(time.Time{}) >= boundaryControl.cancelAfterTimer {
		boundaryControl.cancel()
	}
}

func boundaryContexts(parent context.Context, duration, grace time.Duration) (
	time.Time, context.Context, context.Context, context.CancelFunc,
) {
	outer, cancel := context.WithCancel(parent)
	regular, regularCancel := context.WithCancel(outer)
	boundaryControl.cancel = regularCancel
	return time.Unix(0, 0), outer, regular, cancel
}

func runBoundary(t *testing.T, rate int64, duration, stopAt time.Duration, segment, sequence string, controls ...func(*boundaryClock)) (int64, int64) {
	t.Helper()
	boundaryControl = &boundaryClock{stopAt: stopAt}
	for _, configure := range controls {
		configure(boundaryControl)
	}
	clock := boundaryControl
	var completed atomic.Int64
	runner := simpleRunner(func(context.Context, *lib.State) error {
		if clock.release != nil {
			clock.enteredOnce.Do(func() { close(clock.entered) })
			<-clock.release
		}
		completed.Add(1)
		return nil
	})
	config := &ConstantArrivalRateConfig{
		BaseConfig: BaseConfig{GracefulStop: types.NullDurationFrom(15 * time.Second)},
		Rate:       null.IntFrom(rate), TimeUnit: types.NullDurationFrom(time.Second),
		Duration:        types.NullDurationFrom(duration),
		PreAllocatedVUs: null.IntFrom(16), MaxVUs: null.IntFrom(16),
	}
	if clock.release != nil {
		config.PreAllocatedVUs = null.IntFrom(1)
		config.MaxVUs = null.IntFrom(1)
	}
	test := setupExecutorTest(t, segment, sequence, lib.Options{}, runner, config)
	defer test.cancel()
	output := make(chan metrics.SampleContainer, 10000)
	var dropped int64
	drained := make(chan struct{})
	go func() {
		defer close(drained)
		for container := range output {
			for _, sample := range container.GetSamples() {
				if sample.Metric == test.state.Test.BuiltinMetrics.DroppedIterations {
					dropped += int64(sample.Value)
				}
			}
		}
	}()
	err := test.executor.Run(test.ctx, output)
	close(output)
	<-drained
	require.NoError(t, err)
	// Accepted work and unavailable-VU drops jointly expose the actual offered
	// scheduler slots. This fast virtual-time test is not a capacity benchmark.
	return completed.Load(), dropped
}

func TestBoundaryTruncationDoesNotOfferTerminalSlot(t *testing.T) {
	completed, dropped := runBoundary(t, 4800, time.Second, time.Second, "", "")
	require.Equal(t, int64(4800), completed+dropped,
		"the one-second open schedule must offer exactly 4800 slots")
}

func TestBoundaryDelayedCancellationDoesNotOfferSixExtraSlots(t *testing.T) {
	completed, dropped := runBoundary(t, 4800, time.Second, time.Second+1100*time.Microsecond, "", "")
	require.Equal(t, int64(4800), completed+dropped,
		"late cancellation delivery must not extend the nominal open schedule")
}

func TestBoundaryFullNominalScheduleDoesNotExtendOnDelayedCancellation(t *testing.T) {
	completed, dropped := runBoundary(t, 4800, 90*time.Second, 90*time.Second+1100*time.Microsecond, "", "")
	require.Equal(t, int64(432000), completed+dropped,
		"the full nominal schedule must not offer additional terminal slots")
}

func TestBoundaryCancellationAfterTimerSelectionStopsDispatch(t *testing.T) {
	completed, dropped := runBoundary(t, 10, time.Second, time.Second, "", "", func(clock *boundaryClock) {
		clock.cancelAfterTimer = 300 * time.Millisecond
	})
	require.Equal(t, int64(3), completed+dropped,
		"a selected timer must not dispatch work after regular cancellation")
}

func TestBoundaryFractionalScheduleIncludesLastEligibleSlot(t *testing.T) {
	completed, dropped := runBoundary(t, 3, 500*time.Millisecond, 500*time.Millisecond, "", "")
	require.Equal(t, int64(2), completed+dropped,
		"the half-open schedule contains targets at 0 and one-third second")
}

func TestBoundaryStripedSegmentsPartitionTheGlobalSchedule(t *testing.T) {
	for _, segment := range []string{"0:1/2", "1/2:1"} {
		t.Run(segment, func(t *testing.T) {
			completed, dropped := runBoundary(t, 10, time.Second, time.Second+time.Millisecond,
				segment, "0,1/2,1")
			require.Equal(t, int64(5), completed+dropped,
				"each half must own five of the ten global slots")
		})
	}
}

func TestBoundaryUnavailableVUsRemainDroppedWork(t *testing.T) {
	completed, dropped := runBoundary(t, 10, time.Second, time.Second, "", "", func(clock *boundaryClock) {
		clock.release = make(chan struct{})
		clock.entered = make(chan struct{})
		clock.releaseAfterTimer = 900 * time.Millisecond
	})
	require.Equal(t, int64(10), completed+dropped)
	require.Positive(t, completed, "the first runner entry must complete after release")
	require.Positive(t, dropped, "busy VUs must produce visible drops, not hidden skipped work")
}
