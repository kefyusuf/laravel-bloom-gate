# Consistency Model

The authoritative datastore remains the source of truth.

Bloom synchronization is an operational contract, not a database guarantee. Eloquent observers cannot see raw SQL, Query Builder writes that bypass model events, ETL processes, or external services.

Build/rebuild operations use candidate generations and must account for concurrent writes before promotion. A detected operational false negative is a consistency violation and blocks safe activation.

Infrastructure uncertainty causes bypass to the authoritative lookup.
