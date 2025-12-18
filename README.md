# 🧠 Abrbit Laravel Scout Elasticsearch Driver

**A developer-friendly Laravel Scout driver for Elasticsearch — built for distributed, scalable, and clean search experiences.**  
Crafted with ❤️ by the [Abrbit](https://abrbit.com) team.

---

## 🚀 Features

✅ Plug-and-play with Laravel Scout  
✅ Uses official Elasticsearch REST API  
✅ Auto-maps documents & IDs for distributed clusters  
✅ Supports multi-field search (e.g., `title` + `description`)  
✅ Fully configurable via `config/services.php`  
✅ Developer-friendly syntax — clean and minimal

---

## 📦 Installation

```bash
composer require abrbit/laravel-scout-elasticsearch
```

Then register your search service endpoint and credentials in `.env`:

```bash
SEARCH_URL=https://search.services.abrbit.com
SEARCH_TOKEN=your-api-token
```

---

## ⚙️ Configuration

In your `config/scout.php`, set the driver to `abrbit`:

```php
'driver' => 'abrbit',
```

And in `config/services.php`, add:

```php
'search' => [
    'url' => env('SEARCH_URL', 'https://example.com'),
    'token' => env('SEARCH_TOKEN'),
],
```

---

## 🧩 Usage

You can use Laravel Scout’s native methods directly:

```php
use App\Models\Song;

// Paginate results
$songs = Song::search('عشق')->paginate(20);

// Get paginated raw source data
$songs = Song::search('عشق')->searchSource();
```

## 1. Get Eloquent Models (get())
   This is the standard Scout method. It returns an Eloquent Collection of your models, hydrated from the database based on the IDs returned from the search.

```php
use App\Models\Song;

// Returns a Collection of Song models
$songs = Song::search('عشق')->get();

// Paginate results
$songs = Song::search('عشق')->paginate(20);
```

## 2. Get Raw Source Data (getSource())
   This is the recommended method for APIs or when you don't need full Eloquent models. It returns a clean array containing only the _source data directly from Elasticsearch, avoiding any database queries and providing excellent performance.

```php
// Returns a clean array of the data stored in Elasticsearch
$songs = Song::search('شام')->getSource();

/*
Example Output:
[
    [
        "id" => 1,
        "title" => "شام غریبان",
        "description" => "..."
    ],
    [
        "id" => 2,
        "title" => "شام مهتاب",
        "description" => "..."
    ]
]
*/
```

## 3. Get Paginated Raw Source Data (searchSource())
   This method is similar to `getSource()`, but it supports pagination and is optimized for partial and short-term searches (e.g., 2 characters). It returns a paginated array of source data.

```php
// Returns a paginated array of the data stored in Elasticsearch
$songs = Song::search('شام')->searchSource();

// You can also specify the page and number of items per page
$songs = Song::search('شام')->searchSource($perPage = 15, $page = 2);

/*
Example Output:
[
    'data' => [
        [
            "id" => 1,
            "title" => "شام غریبان",
            "description" => "..."
        ],
        [
            "id" => 2,
            "title" => "شام مهتاب",
            "description" => "..."
        ]
    ],
    'total' => 100,
    'per_page' => 15,
    'current_page' => 2,
]
*/
```

## 4. Get the Raw Elasticsearch Response (raw())
   This method returns the entire, unprocessed JSON response from Elasticsearch. It is useful for debugging or when you need access to metadata like took, _shards, or max_score.

```php
// Returns the complete, raw response from the search engine
$rawResponse = Song::search('شام')->raw();
```


Your Eloquent model only needs to implement the `Searchable` trait:

```php
use Laravel\Scout\Searchable;
use Illuminate\Database\Eloquent\Model;

class Song extends Model
{
    use Searchable;

    protected $fillable = ['title', 'description'];

    public function toSearchableArray()
    {
        return [
            'id' => $this->getKey(),
            'title' => $this->title,
            'description' => $this->description,
        ];
    }
}
```

---

## 🔍 Example Query

Here’s what an example query looks like under the hood:

```json
{
  "from": 0,
  "size": 20,
  "query": {
    "multi_match": {
      "fields": ["title", "description"],
      "query": "شام"
    }
  }
}
```

---

## 🧠 How It Works

`AbrbitSearchEngine` is a custom Laravel Scout engine that:

- Sends search requests to your Elasticsearch instance.
- Handles `_id` assignment automatically by Elasticsearch.
- Supports distributed, multi-tenant indexes like `tenant_songs` or `user_posts`.
- Maps `_source` data back into Eloquent models seamlessly.

Example search response:

```json
{
  "id": "Swv5oJkBk5ZOaUeK3x_O",
  "title": "شام غریبان",
  "description": "نوحه زیبای حاج محمود کریمی",
  "score": 6.6
}
```

---

## 🧰 Developer Notes

- Designed for **SaaS and multi-tenant** applications.  
- Compatible with any REST-compatible Elasticsearch cluster (v8+ recommended).  
- Works perfectly with Laravel Scout’s indexing pipeline.

---

## 🧑‍💻 Contributing

We welcome contributions!  
Feel free to open issues or submit pull requests.

---

## ⚖️ License

Released under the [MIT License](LICENSE).

---

## 🌐 About Abrbit

Abrbit provides modern cloud infrastructure and SaaS services —  
from DNS & mail hosting to AI, storage, and classroom platforms.

> 💡 Visit us at [abrbit.com](https://abrbit.com)