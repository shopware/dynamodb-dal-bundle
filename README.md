# DynamoDB DAL Bundle

A data abstraction layer for DynamoDB, as a Symfony bundle.

Entities are plain PHP classes annotated with `#[Table]` and `#[Field]`.
An application lists them in the bundle configuration, together with the table their items live in:

```yaml
shopware_dynamodb_dal:
    entities:
        App\Entity\OrderEntity: '%env(DYNAMODB_TABLE_ORDER)%'
```
