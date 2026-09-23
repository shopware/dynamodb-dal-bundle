---
title: Opaque pagination cursors
date: 2026-09-23
---

# Opaque pagination cursors

## Status

Proposed. Replaces the bundle's original cursor model: `Cursor`, `CursorCollection`,
`CursorHistory` and `CursorNormalizer` in `Client\Cursor`.

## Context

DynamoDB has exactly one pagination primitive. A response's `LastEvaluatedKey` goes back as the next
request's `ExclusiveStartKey`. It holds the key attributes of one item (the table key, plus the index key
on a GSI query) in DynamoDB's attribute form, and it only means something to the query that produced it.

The bundle wraps that primitive in four classes:

- `Cursor` holds the boundary item's key as *deserialized* values (`DateTimeImmutable`, enums, …) plus the
  logical table name. `Cursor::from()` builds it from an entity.
- `CursorCollection` holds several named cursors, for a page merged from several queries.
- `CursorHistory` holds the `CursorCollection` of every page visited, so a view can go back.
- `CursorNormalizer` is a Symfony serializer normalizer that carries all of the above through a URL as
  JSON.

Deserialized values were chosen for a sound reason: nothing else in the codebase sees DynamoDB's attribute
form, and the DAL `Serializer` is the one place that converts it. In practice the model is heavier than the
problem it solves:

1. **The key makes a round trip it does not need.** DynamoDB returns each item's key attributes in exactly
   the form `ExclusiveStartKey` takes. The bundle deserializes the item into an entity, then reads the key
   fields back off the entity through the key schema, and serializes them again on the next request. The
   normalizer serializes and deserializes them once more at the HTTP edge.
2. **The cursor carries what the query already knows.** The table name is in the cursor only so that the
   normalizer can look the definition up again on the way in. That lookup needs the definition registry.
   The normalizer sits inside the framework serializer, which the registry itself depends on, so two lazy
   services are needed to break the cycle. `symfony/serializer` became a suggested dependency for this
   alone.
3. **The URL exposes the schema.** A cursor travels as JSON with real field names and `{S|N|B}` wrappers.
   Anyone can read the key layout off a pager link, and anyone can edit it, so the normalizer has to
   defend against tampered input.
4. **Going back costs a URL that grows with every page.** The cursors are treated as forward-only, so a
   "Previous" link carries the position of every page visited so far. Yet DynamoDB can read a query in
   either direction; only a scan cannot go back.
5. **View concerns live in the data access layer.** Named cursor sets and a page history are how one kind
   of screen navigates. They sit in the DAL next to a normalizer that is only useful to such screens.

The current design considered opaque cursors and rejected them twice:

- **Returning `LastEvaluatedKey` as it comes.** Pages come out short under a filter, because DynamoDB's
  `Limit` counts items read, not items matched. Whether another page exists is only a guess. And a view
  that merges several queries still needs key logic to resume each query after the last item *it* put on
  the page.
- **A cursor in DynamoDB's attribute form.** It would leak the wire format into a value object that
  callers handle.

Both objections are about what callers see and where a page boundary lies, not about how the cursor is
encoded. They can be met without the deserialized model.

## Decision

### 1. A cursor is an opaque, URL-safe token

Callers only ever hold a string: the base64url encoding of the boundary item's raw key attributes plus a
direction flag. `Cursor`, the decoded form, is `@internal`. The format is not a contract and may change.

```php
$page = $client->search($definition, new QueryInput($keyCondition, cursor: $token, limit: 25))->page();

$page->items;
$page->next;      // ?string, resumes after the last item
$page->previous;  // ?string, reads back from the first item; null on the first page and for a scan
```

No caller handles attribute values, so the objection to the attribute form no longer applies: that form
never leaves the bundle.

### 2. Tokens are cut from the raw item at the visible boundary

`ReaderClient::search()` yields each entity keyed by its raw start key: the item's table key attributes,
plus the index key attributes on a GSI query. `SearchOutput::page()` still fetches one item more than the
limit and cuts the page to size, so a filter cannot leave the page short. The tokens come from the first
and last *visible* items. There is no entity round trip, no table name and no serializer involved.

This keeps what returning `LastEvaluatedKey` as it comes would lose: full pages under a filter, and a
reliable answer to whether another page exists. The key schema declared on the entity is still what names
the key attributes.

### 3. Tokens for any item on a page

`Page::cursorAfter($item)` and `Page::cursorBefore($item)` make a token right after, or right before, any
item on the page. A view that merges several queries resumes each one after the last item it put on the
merged page, which is the one case `next` cannot cover. The view needs no knowledge of keys to do so.

### 4. Queries go back natively

A backward token runs the same query with `ScanIndexForward` flipped, starting from the page's first item,
and `page()` restores the original order. Going back through a query needs no history. A scan has no order
to reverse, so it only pages forward, and a backward token on a scan is refused.

### 5. An optional history of tokens

`CursorHistory` remains, reduced to what DynamoDB cannot answer by itself: going back through a scan, and
the page number. It is a list of tokens with a URL-safe string form (`toString()`/`fromString()`, tokens
joined by `.`), so it needs no serializer. `CursorHistory::PATTERN` validates that form where it enters,
for example as a URL parameter. `combine()` and `split()` fold the named tokens of a merged page into one
history position. A view over a single query does not need a history at all.

### 6. Bad tokens fail loudly

The bundle raises `InvalidCursorException` (a `DALException`) for a token that does not decode, whose key
does not match the table or index being queried, or that asks a scan to go backward. Tokens usually come
from a URL, so callers should treat the exception as a client error, or restart at page one.

### 7. Removed

`CursorCollection`, `CursorNormalizer` and its service wiring, `Cursor::from()`,
`Serializer::serializeCursor()`, and the `symfony/serializer` suggestion.

## Consequences

- Positive:
  - Pagination comes down to a string, a `Page` and an optional history. The normalizer, its lazy wiring
    and the registry lookup are gone.
  - URLs no longer expose the key schema, and the pager links of a single-query view stay the same length
    on every page.
  - Each request only encodes a handful of attributes; nothing makes a serialization round trip.
- Negative / trade-offs:
  - **Breaking change** to `QueryInput::$cursor` and `ScanInput::$cursor` (now `?string`), to `Page`
    (`nextCursor` becomes `next` and `previous`), and to every `Cursor*` class. Tokens made before the
    change no longer decode after it. They are short-lived UI state, so open paginated views reset once.
  - A token is tied to the query that produced it. Replayed against another table or index it is refused
    rather than quietly reinterpreted, so a caller that lets criteria change mid-listing has to catch
    `InvalidCursorException`.
  - Tokens are not signed. A forged token can only move the starting point within what the query already
    allows: DynamoDB rejects a start key outside the key condition, and filters still apply. Signing can be
    added later without changing the API.
  - As with any keyset pagination, rows added or deleted between requests can shift page boundaries when
    going back. The old history replayed stale positions, so this is no worse than before.
  - A search started with a backward token and iterated directly, rather than through `page()`, yields its
    items in reverse order.
  - A history still grows with every page. A view over a single query can avoid it by using
    `Page::$previous`.
  - A `SearchOutput` built from a hand-written generator, such as a test double, streams as before. Its
    tokens are only usable if the generator keys each entity by its raw start key; otherwise they are
    refused when used.

