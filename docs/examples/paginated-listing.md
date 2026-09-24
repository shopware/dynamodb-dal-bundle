# Paginated listing

- [The entity](#the-entity)
- [Listing a query](#listing-a-query)
- [Listing a scan, with page numbers](#listing-a-scan-with-page-numbers)
- [Keeping filters in the links](#keeping-filters-in-the-links)
- [A page merged from several queries](#a-page-merged-from-several-queries)
- [Which approach to use](#which-approach-to-use)

This example builds an article listing with "Previous" and "Next" links from a controller and a Twig
template. Each page starts where the previous one ended, so reaching page 10 never reads pages 1 to 9
again.

A page position is an **opaque token**: a URL-safe string you get from a `Page` and pass back as the
`cursor` of the same search. Put it in a link and read it back from the query string; never build or parse
one yourself.

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
directions, so the page itself provides both links and the URL carries a single `cursor` parameter.

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
            $page = $this->client->search(ArticleEntity::class, new QueryInput(
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

- `page.next` is `null` on the last page, and `page.previous` is `null` on the first page.
- `page()` fetches one item more than `limit` to find out whether a next page exists. Pages are cut to size
  on this side, so a `filter:` on the input never leaves a page short; only the last page can be.
- A bad token is only noticed when the page is read, which is why the `try` wraps `->page()`.

## Listing a scan, with page numbers

A scan has no order to reverse, so it can only page forward and `page.previous` is always `null`. To go back
anyway, or to show a page number, carry the visited positions along in a `CursorHistory`. It turns into a
single URL-safe string with `toString()` and back with `fromString()`.

```php
use Shopware\DynamodbDalBundle\Client\CursorHistory;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;

    #[Route('/articles/all', name: 'article_all', methods: ['GET'])]
    public function all(#[MapQueryParameter] ?string $history = null): Response
    {
        try {
            $visited = CursorHistory::fromString($history); // null or '' is page 1
            $page = $this->client->search(ArticleEntity::class, new ScanInput(cursor: $visited->current(), limit: 20))->page();
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

- Pass `.toString` to `path()`, never the `CursorHistory` object itself. The router would turn the object's
  public properties into nested query parameters.
- The history grows by one token per page. For a query you only need it if you want page numbers.
- To validate the parameter where it enters, for example on a `#[MapQueryString]` DTO, use
  `#[Assert\Regex(CursorHistory::PATTERN)]`.

## Keeping filters in the links

A token only fits the search that produced it, so every pager link has to repeat that search's criteria.
The simplest way is to merge the token into the current query parameters:

```twig
<a href="{{ path('article_list', app.request.query.all|merge({ cursor: page.next })) }}">Next</a>
```

A search form, on the other hand, should *not* submit the cursor: new criteria start at page 1. If a token
does reach a different search, it is refused rather than returning rows from the wrong place. A token from
another table or index is refused by the DAL with `InvalidCursorException`. A token from another partition of
the same index (say, the listing switched from `published` to `draft`) is refused by DynamoDB, with its own
error.

## A page merged from several queries

Sometimes one page combines several queries, such as one query per status merged newest first. Each query
then has to resume after **its own last item that made it onto the page**. `page.next` points after the
last item that query *fetched*, which may have been cut off. `Page::cursorAfter($item)` gives a token for
any item of the page, and `CursorHistory::combine()` / `split()` carry one token per query in a single
history entry.

Inside an action like `all()` above, with `$visited` being the `CursorHistory` read from the URL:

```php
$positions = CursorHistory::split($visited->current()); // ['draft' => token, …]; [] on page 1

$pages = [];
$items = [];
foreach (['draft', 'published'] as $status) {
    $pages[$status] = $this->client->search(ArticleEntity::class, new QueryInput(
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

// Each status resumes after its last visible item; a status with none keeps its position.
foreach ($visible as $article) {
    $positions[$article->status] = $pages[$article->status]->cursorAfter($article);
}

$nextHistory = $hasMore ? $visited->append(CursorHistory::combine($positions)) : null;
```

The template is the same as for the scan listing.

## Which approach to use

| Listing | Navigation | Carry in the URL |
|---|---|---|
| One query | `page.next` / `page.previous` | `cursor`: one token |
| One query, with page numbers | `CursorHistory` | `history`: grows with each page |
| A scan | `CursorHistory` for "Previous"; `page.next` alone for forward-only | `history`, or `cursor` |
| Several queries merged into one page | `cursorAfter()` + `CursorHistory::combine()` | `history` |
