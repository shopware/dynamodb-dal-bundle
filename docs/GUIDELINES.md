# Guidelines

- [Guidelines](#guidelines)
  - [General](#general)
  - [Public API](#public-api)
  - [Services and configuration](#services-and-configuration)
  - [Exceptions](#exceptions)
  - [Comments and docblocks](#comments-and-docblocks)
  - [Documentation](#documentation)
  - [Testing](#testing)
  - [Commits](#commits)

## General

- The bundle supports PHP 8.4 and 8.5, and Symfony 7.3 and 8. CI runs each PHP version with both the lowest and the highest dependencies, so don't use an API outside that range
- `composer phpstan` (level 9, strict rules) and `composer ecs` must pass. `composer ecs:fix` applies the code style
- Inputs, outputs and value objects are `final` and `readonly` where possible
- `AttributeValue` stays inside the bundle. Public methods take and return entities and PHP values
- A wrongly declared entity fails the container build, not a request. Validate it in `DefinitionBuilder` or a compiler pass
- Generics use `@template`, so the entity type carries through from a definition or input to its output
  (`EntityDefinition<Entity>` becomes `SearchOutput<Entity>`)

## Public API

- Everything outside the consumer-facing API is `@internal`. The tag goes first in the class docblock
- Constructors of autowired services and of value objects the container builds are `@internal`, even when the
  class is public. The class is API; its constructor signature is not
- Extension points are abstract classes (`AbstractFieldSerializer`, `AbstractNormalizer`) or interfaces
  (`ExpressionInterface`). The bundle's own implementations of them are `@internal`
- Decide which side of that boundary a new class belongs to when you add it

## Services and configuration

- Services are registered in `config/*.php` with explicit arguments. The bundle doesn't rely on the application's autowiring or autoconfiguration
- Classes that are not services, such as inputs, outputs and value objects, carry `#[Exclude]`
- An optional dependency goes under `suggest` in `composer.json`. Whatever needs it is registered behind a `class_exists()` check
- Development tooling (commands and the profiler) is loaded in the `dev` environment only

## Exceptions

- A runtime failure throws a `final` exception that extends an SPL exception and implements `DALException`.
  Catching `DALException` then catches everything the DAL throws
- The exception class says what went wrong. Its public readonly properties say where, such as the definition and the field
- Rethrow a `DALException` as it is. Wrap any other throwable in an exception that names the field
- Use `\LogicException` for programming errors and container build failures
- AsyncAws exceptions, such as `ConditionalCheckFailedException`, pass through unwrapped

## Comments and docblocks

- `/** … */` is for docblocks and annotations. A comment inside a method body uses `//`
- A comment explains why. It doesn't repeat what the code, a declaration or an attribute already says
- A docblock with only types (`@param`, `@var`, `@return`, `@throws`) needs no prose
- Reference related code with `{@see}`

## Documentation

- The bundle is public. Its docs and ADRs don't name internal projects or consumers
- The README is an overview. Setup goes into `docs/QUICK_SETUP.md`, and longer material into an example under `docs/examples/`, both linked from the README
- Examples show the current API. Update them in the same change as the API
- A design decision gets an ADR at `docs/adr/YYYY-MM-DD-slug.md`: frontmatter with `title` and `date`, then the sections Status, Context, Decision and Consequences

## Testing

- Unit tests (`tests/Unit`) test one class. Integration tests (`tests/Integration`) run against DynamoDB Local through a real kernel, without mocks
- Every test class declares `#[CoversClass]`
- A test method's name states the behaviour as a sentence, for example `testUpdateRemovesTheMapEntryGivenAsNull`
- Call assertions statically: `static::assertSame()`
- Fixtures live under `Fixtures/`, next to the suite that uses them

## Commits

- Use Conventional Commits (`feat:`, `fix:`, `refactor:`, …) and mark a breaking change with `!`
