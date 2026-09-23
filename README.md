# DynamoDB DAL Bundle

A data abstraction layer for DynamoDB, as a Symfony bundle.

Entities are plain PHP classes annotated with `#[Table]` and `#[Field]`. A compiler pass turns them
into container-level definitions, so the table layout, the key schema and the per-field serializers
are resolved at build time.
