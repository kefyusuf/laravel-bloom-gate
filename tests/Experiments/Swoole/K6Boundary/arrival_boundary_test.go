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
	receiverEntered    chan uint64
	receiverRelease    chan struct{}
	receiverReleaseOnce sync.Once
	receiverNextID     atomic.Uint64
	receiverGateReady  bool
}

var boundaryControl *boundaryClock

// releaseAllReceivers unblocks every test-gated pool receiver exactly once.
// The drop threshold, watchdog and cleanup paths may call it concurrently.
func (clock *boundaryClock) releaseAllReceivers() {
	if clock.receiverRelease != nil {
		clock.receiverReleaseOnce.Do(func() { close(clock.receiverRelease) })
	}
}

type boundaryTimer struct{ C chan time.Time }

// boundaryNewTimer blocks the first virtual dispatch until two distinct real
// pool receivers acknowledge the test-only gate; it fails closed otherwise.
func boundaryNewTimer(time.Duration) *boundaryTimer {
	timer := &boundaryTimer{C: make(chan time.Time, 1)}
	// Wait for both real pool goroutines before the executor can offer slot zero.
	// The unhooked fixture must fail closed; AddVU's handshake alone is not readiness.
	if boundaryControl.receiverEntered != nil {
		deadline := time.NewTimer(5 * time.Second)
		defer deadline.Stop()
		seen := make(map[uint64]struct{}, 2)
		for len(seen) < 2 {
			select {
			case id := <-boundaryControl.receiverEntered:
				if id == 0 {
					boundaryControl.releaseAllReceivers()
					return timer
				}
				if _, duplicate := seen[id]; duplicate {
					boundaryControl.releaseAllReceivers()
					return timer
				}
				seen[id] = struct{}{}
			case <-deadline.C:
				boundaryControl.releaseAllReceivers()
				return timer
			}
		}
		boundaryControl.receiverGateReady = true
	}
	return timer
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

// TestBoundaryNoReadyReceiversReportRealDrops uses only the upstream VU pool,
// executor and dropped-iteration metric. It is not a real-load capacity result.
func TestBoundaryNoReadyReceiversReportRealDrops(t *testing.T) {
	clock := &boundaryClock{
		stopAt: time.Second,
		receiverEntered: make(chan uint64, 2),
		receiverRelease: make(chan struct{}),
	}
	boundaryControl = clock
	defer func() {
		clock.releaseAllReceivers()
		boundaryControl = nil
	}()

	var completed atomic.Int64
	runner := simpleRunner(func(context.Context, *lib.State) error {
		completed.Add(1)
		return nil
	})
	config := &ConstantArrivalRateConfig{
		BaseConfig: BaseConfig{GracefulStop: types.NullDurationFrom(15 * time.Second)},
		Rate: null.IntFrom(3), TimeUnit: types.NullDurationFrom(time.Second),
		Duration: types.NullDurationFrom(time.Second),
		PreAllocatedVUs: null.IntFrom(2), MaxVUs: null.IntFrom(2),
	}
	fixture := setupExecutorTest(t, "", "", lib.Options{}, runner, config)
	defer fixture.cancel()

	output := make(chan metrics.SampleContainer, 32)
	var dropped int64
	drained := make(chan struct{})
	go func() {
		defer close(drained)
		for container := range output {
			for _, sample := range container.GetSamples() {
				if sample.Metric == fixture.state.Test.BuiltinMetrics.DroppedIterations {
					dropped += int64(sample.Value)
					if dropped >= 3 {
						clock.releaseAllReceivers()
					}
				}
			}
		}
	}()

	// A missing acknowledgement, metric or deadlocked Close must release both
	// receivers and fail, not convert the watchdog into a successful result.
	watchdogStopped := make(chan struct{})
	watchdogExpired := make(chan struct{})
	go func() {
		select {
		case <-time.After(5 * time.Second):
			clock.releaseAllReceivers()
			close(watchdogExpired)
		case <-watchdogStopped:
		}
	}()
	defer close(watchdogStopped)

	err := fixture.executor.Run(fixture.ctx, output)
	close(output)
	<-drained

	select {
	case <-watchdogExpired:
		t.Fatal("receiver gate watchdog expired")
	default:
	}
	require.NoError(t, err)
	require.True(t, clock.receiverGateReady, "two unique receiver-gate acknowledgements must precede dispatch")
	require.Equal(t, int64(0), completed.Load(), "no receiver may take one of the three slots")
	require.Equal(t, int64(3), dropped, "the real executor must emit three dropped iterations")
	require.Equal(t, int64(3), completed.Load()+dropped, "all three virtual slots must be accounted for")
}
