# Paginated listing

- [Paginated listing](#paginated-listing)
  - [Which approach to use](#which-approach-to-use)
  - [The entity](#the-entity)
  - [Listing a query](#listing-a-query)
  - [Listing a scan, with page numbers](#listing-a-scan-with-page-numbers)
  - [Keeping filters in the links](#keeping-filters-in-the-links)
  - [A page merged from several queries](#a-page-merged-from-several-queries)
  - [Testing a listing](#testing-a-listing)

This example builds an article listing with "Previous" and "Next" links, from a controller and a Twig template. Each
page starts where the previous one ended, so reaching page 10 never reads pages 1 to 9 again.

A page position is an **opaque token**: a URL-safe string you get from a `Page` and pass back as the `cursor` of the
same search. Put it in a link, and read it back from the query string. Never build or parse a token yourself.

## Which approach to use

| Listing | Navigation | Carry in the URL | See |
|---|---|---|---|
| One query | `page.next` and `page.previous` | `cursor`: one token | [Listing a query](#listing-a-query) |
| One query, with page numbers | `CursorHistory` | `history`: grows with each page | [Listing a scan](#listing-a-scan-with-page-numbers) |
| A scan | `CursorHistory` to go back, or `page.next` alone to go forward only | `history`, or `cursor` | [Listing a scan](#listing-a-scan-with-page-numbers) |
| Several queries merged into one page | `cursorAfter()` and `CursorHistory::combine()` | `history` | [A page merged from several queries](#a-page-merged-from-several-queries) |

## The entity

```php
namespace App\Entity;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;
use Shopware\DynamodbDalBundle\Definition\IndexSchema;

#[Table(
    name: 'article',
    hashKey: 'id',
    indexes: [new IndexSchema('statusCreatedAtIndex', hashKey: 'status', rangeKey: 'createdAt')],
)]
class ArticleEntity extends AbstractEntity
{
    #[Field]
    public string $id;

    #[Field]
    public string $status = 'draft';

    #[Field]
    public \DateTimeImmutable $createdAt;

    #[Field]
    public string $title;
}
```

```yaml
# config/packages/shopware_dynamodb_dal.yaml
shopware_dynamodb_dal:
    entities:
        App\Entity\ArticleEntity: '%env(DYNAMODB_TABLE_ARTICLE)%'
```

## Listing a query

This lists published articles, newest first, from the `statusCreatedAtIndex`. A query can be read in both
directions, so the page itself provides both links, and the URL carries a single `cursor` parameter.

```php
namespace App\Controller;

use App\Entity\ArticleEntity;
use Shopware\DynamodbDalBundle\Client\Client;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Exception\InvalidCursorException;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final class ArticleController extends AbstractController
{
    public function __construct(
        private readonly Client $client,
    ) {
    }

    #[Route('/articles', name: 'article_list', methods: ['GET'])]
    public function list(#[MapQueryParameter] ?string $cursor = null): Response
    {
        try {
            $page = $this->client->search(new QueryInput(
                ArticleEntity::class,
                Filter::equals('status', 'published'),
                index: 'statusCreatedAtIndex',
                forward: false, // newest first
                cursor: $cursor,
                limit: 20,
            ))->page();
        } catch (InvalidCursorException) {
            // The token was edited, or it belongs to another table or index: start over.
            return $this->redirectToRoute('article_list');
        }

        return $this->render('article/list.html.twig', ['page' => $page]);
    }
}
```

`templates/article/list.html.twig`:

```twig
<table>
    {% for article in page.items %}
        <tr>
            <td>{{ article.title }}</td>
            <td>{{ article.createdAt|date('Y-m-d') }}</td>
        </tr>
    {% else %}
        <tr><td colspan="2">No articles.</td></tr>
    {% endfor %}
</table>

<nav>
    {% if page.previous %}
        <a href="{{ path('article_list', { cursor: page.previous }) }}">Previous</a>
    {% endif %}
    {% if page.next %}
        <a href="{{ path('article_list', { cursor: page.next }) }}">Next</a>
    {% endif %}
</nav>
```

`page.next` is `null` on the last page, and `page.previous` is `null` on the first page.

### How it works

- `page()` fetches one entity more than `limit`, to find out whether a next page exists. Pages are cut to size on
  this side, so a `filter:` on the input doesn't shorten a page.
- `page.previous` reads the same query backward from the page's first entity. Going back therefore needs no history
  of the pages visited.
- A `limit` below 1 counts as 1.

### Pitfalls

- A bad token is only noticed when the page is read, so the `try` has to wrap `->page()`.
- An edited token can pass the bundle's checks and fail at DynamoDB instead, with a `ClientException`. The `catch`
  above only starts over for a token the bundle refuses itself.
- Entities added or removed before the current page move the page boundaries. Going back can then end on a first page
  with fewer than `limit` entities.
- If the entities a link leads to are deleted before the link is followed, the page is empty and has neither token,
  although the pages before it still exist. `page.previous` being `null` then doesn't mean the first page. A listing
  that carries a [`CursorHistory`](#listing-a-scan-with-page-numbers) can still go back.
- A search from a `previous` token reads backward. Read it through `page()`, which puts the entities back into the
  query's order. `foreach`, `toArray()` and `first()` hand them out in reverse.

## Listing a scan, with page numbers

A scan has no order to reverse, so it can only page forward, and `page.previous` is always `null`. To go back anyway,
or to show a page number, carry the visited positions along in a `CursorHistory`. It turns into a single URL-safe
string with `toString()`, and back with `fromString()`.

```php
use Shopware\DynamodbDalBundle\Client\CursorHistory;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;

    #[Route('/articles/all', name: 'article_all', methods: ['GET'])]
    public function all(#[MapQueryParameter] ?string $history = null): Response
    {
        try {
            $visited = CursorHistory::fromString($history); // null or '' is page 1
            $page = $this->client
                ->search(new ScanInput(ArticleEntity::class, cursor: $visited->current(), limit: 20))
                ->page();
        } catch (InvalidCursorException) {
            return $this->redirectToRoute('article_all');
        }

        return $this->render('article/all.html.twig', [
            'page' => $page,
            'history' => $visited,
            'nextHistory' => $visited->advance($page->next), // null on the last page
        ]);
    }
```

`templates/article/all.html.twig`:

```twig
{# … the same table as above … #}

<nav>
    {% if history.previous is not null %}
        <a href="{{ path('article_all', { history: history.previous.toString }) }}">Previous</a>
    {% endif %}

    <span>Page {{ history.page }}</span>

    {% if nextHistory is not null %}
        <a href="{{ path('article_all', { history: nextHistory.toString }) }}">Next</a>
    {% endif %}
</nav>
```

| `CursorHistory` method | Returns |
|---|---|
| `current()` | The token the current page resumes from, or `null` on page 1 |
| `advance($token)` | The history one page further, or `null` for a `null` token, so `$page->next` passes straight in |
| `previous()` | The history one page back, or `null` on page 1 |
| `page()` | The current page number, starting at 1 |

To validate the parameter where it enters, for example on a `#[MapQueryString]` DTO, use
`#[Assert\Regex(CursorHistory::PATTERN)]`. A query needs a history only for page numbers.

### Pitfalls

- Pass `.toString` to `path()`, never the `CursorHistory` itself. The router would turn the object's public properties
  into nested query parameters.
- The history grows by one token per page, so deep pagination eventually runs into URL length limits.

## Keeping filters in the links

A token only fits the search that produced it, so every pager link has to repeat that search's criteria. The simplest
way is to merge the token into the current query parameters:

```twig
<a href="{{ path('article_list', app.request.query.all|merge({ cursor: page.next })) }}">Next</a>
```

A search form, on the other hand, should *not* submit the cursor: new criteria start at page 1.

### How it works

- A token holds the key of the entity it resumes from, and the direction. It holds nothing about the criteria.
- The bundle refuses a token from another table or index with `InvalidCursorException`.
- DynamoDB refuses a token from another partition of the same index, such as a listing that switched from
  `published` to `draft`, with its own error.

### Pitfalls

- A token from the same table or index with a different filter or sort direction is accepted. The search then
  resumes from a position that means nothing to it, without an error.

## A page merged from several queries

Sometimes one page combines several queries, such as one query per status, merged newest first. Each query then has
to resume after **its own last entity that made it onto the page**. `page.next` points after the last entity the
query *fetched*, which may have been cut off. `Page::cursorAfter($entity)` gives a token for any entity of the page,
and `CursorHistory::combine()` and `split()` carry one token per query in a single history entry.

Inside an action like `all()` above, with `$visited` being the `CursorHistory` read from the URL:

```php
$positions = CursorHistory::split($visited->current()); // ['draft' => token, …]; [] on page 1

$pages = [];
$items = [];
foreach (['draft', 'published'] as $status) {
    $pages[$status] = $this->client->search(new QueryInput(
        ArticleEntity::class,
        Filter::equals('status', $status),
        index: 'statusCreatedAtIndex',
        forward: false,
        cursor: $positions[$status] ?? null,
        limit: 20,
    ))->page();
    $items = [...$items, ...$pages[$status]->items];
}

usort($items, static fn (ArticleEntity $a, ArticleEntity $b): int => $b->createdAt <=> $a->createdAt);
$visible = array_slice($items, 0, 20);

$hasMore = count($items) > 20;
foreach ($pages as $page) {
    $hasMore = $hasMore || $page->next !== null;
}

// Each status resumes after its last visible article; a status with none keeps its position.
foreach ($visible as $article) {
    $positions[$article->status] = $pages[$article->status]->cursorAfter($article);
}

$nextHistory = $hasMore ? $visited->advance(CursorHistory::combine($positions)) : null;
```

The template is the same as for the scan listing. `Page::cursorBefore($entity)` is the backward counterpart of
`cursorAfter()`.

### Pitfalls

- Name the positions with strings that PHP keeps as strings, such as `'status-200'`. PHP turns a numeric array key
  such as `'200'` into an integer, and `split()` then refuses the position it was given.
- `cursorAfter()` and `cursorBefore()` take an entity of that page, by identity. Any other entity throws an
  `\InvalidArgumentException`.

## Testing a listing

`OutputFactory::search()`, from the bundle's `Test` namespace, stands in for a search in a unit test. It streams the
entities it is given, limited and paged as the input says. It builds its tokens from the key attributes that `$keyOf`
returns, as a real search builds them from what DynamoDB returns. A test compares the token that the code under test
passes on with one that its own stand-in hands out:

```php
use Shopware\DynamodbDalBundle\Client\Client;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Output\SearchOutput;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Test\OutputFactory;

$keyOf = static fn (ArticleEntity $article): array => [
    'id' => $article->id,
    'status' => $article->status,
    'createdAt' => $article->createdAt->getTimestamp(),
];

$client = $this->createMock(Client::class);
$client->method('search')->willReturnCallback(
    static fn (QueryInput $query): SearchOutput => OutputFactory::search($query, [$first, $second], $keyOf),
);

// … run the controller with a limit of 1 …

$query = new QueryInput(
    ArticleEntity::class,
    Filter::equals('status', 'published'),
    index: 'statusCreatedAtIndex',
    forward: false,
    limit: 1,
);
$expected = OutputFactory::search($query, [$first, $second], $keyOf)->page()->cursorAfter($first);
static::assertSame($expected, $nextCursorTheControllerLinked);
```

`$keyOf` returns the key attributes that a search of the input resumes from, as strings and numbers: the table key,
plus the index key for a query of an index. [Testing](testing.md) covers doubles of the client in general.

### Pitfalls

- Without `$keyOf`, a page still hands out tokens, but every search refuses them.
- `OutputFactory::search()` doesn't evaluate the key condition or the filter. It streams exactly the entities it is
  given.
