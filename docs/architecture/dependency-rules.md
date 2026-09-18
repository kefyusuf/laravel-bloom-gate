# Dependency Rules

Allowed directions:

- `Core`: PHP standard library only.
- `Contracts`: Core.
- `Lifecycle`: Core and Contracts.
- `Application`: Core, Contracts, Lifecycle.
- `Drivers`: Core and Contracts.
- `Laravel`: any internal layer plus Illuminate.

Forbidden outside the Laravel adapter:

```text
use Illuminate\...
```

These boundaries are executable CI rules, not documentation-only guidance.
