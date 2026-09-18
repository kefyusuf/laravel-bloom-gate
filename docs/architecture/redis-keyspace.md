# Redis Keyspace

Redis keys belonging to one logical filter must share a Redis Cluster hash tag.

Conceptual shape:

```text
lbg:{products.sku}:state
lbg:{products.sku}:active
lbg:{products.sku}:candidate
lbg:{products.sku}:v:000001:bf
lbg:{products.sku}:v:000001:meta
```

The prefix may be configurable. The hash-tag strategy is not.

Filter names must exclude braces so user input cannot corrupt slot selection. Cluster-aware key design does not become an official Redis Cluster support claim until dedicated integration tests exist.
