# WP Abilities API Test

> **This plugin is a learning example.** It is not production-ready and should not be used on live sites. Its only purpose is to demonstrate how to register abilities using the [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/) introduced in WordPress 6.9.

---

## What this plugin does

It registers two abilities and exposes a UI in the WordPress admin to trigger them:

- **Write Post** (`wp-abilities-api-test/write-post`) — generates a Gutenberg draft post from a title, optional notes, and a word-count target using the WordPress AI Client.
- **Serialize Blocks** (`wp-abilities-api-test/serialize-blocks`) — pure utility ability: takes a JSON array of block descriptors and returns a serialized Gutenberg `post_content` string. Can be called independently by any AI agent.

A **Generate with AI** button appears next to the standard **Add New Post** button on the Posts list screen. Clicking it opens a dialog where you enter a title, optional notes, and a post-length slider before generating.

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 6.9 or newer (7.0 recommended) |
| PHP | 8.1 or newer |
| WordPress AI plugin | Active and configured with a provider (e.g. Anthropic) |

---

## Installation

1. Clone or download this repository into your `wp-content/plugins/` directory:
   ```bash
   git clone https://github.com/your-username/wp-abilities-api-test.git
   ```
2. Activate the plugin from **Plugins → Installed Plugins** in the WordPress admin.
3. Ensure the WordPress AI plugin is active and an AI provider is connected (**Settings → AI**).

---

## Plugin architecture

The plugin uses three OOP layers on top of the Abilities API. This is not a framework — it is just a small composition pattern to make adding new abilities quick and consistent.

```
wp-abilities-api-test/
├── wp-abilities-api-test.php                    ← Entry point, REST route, JS/CSS enqueue
├── includes/
│   ├── class-ability.php                        ← Abstract base class all abilities extend
│   ├── class-ability-factory.php                ← Holds a keyed collection of ability instances
│   ├── class-ability-orchestrator.php           ← Hooks the factory into WordPress + registers categories
│   ├── class-block-serializer.php               ← Pure serializer: JSON block array → post_content string
│   └── abilities/
│       ├── class-write-post-ability.php         ← Orchestrates the full post-generation pipeline
│       └── class-serialize-blocks-ability.php   ← Exposes Block_Serializer as a standalone ability
└── assets/
    ├── js/post-list-dialog.js                   ← Trigger button + modal on the Posts list screen
    └── css/post-list-dialog.css                 ← Dialog styles
```

### `Ability` (abstract base class)

Every ability extends this class and implements six abstract methods. The `register()` method is inherited and calls `wp_register_ability()` automatically — no boilerplate per ability.

```php
abstract public function get_name(): string;        // e.g. 'my-plugin/do-something'
abstract public function get_label(): string;
abstract public function get_description(): string;
abstract public function get_category(): string;    // slug of a registered category
abstract public function get_input_schema(): array; // JSON Schema array
abstract public function get_output_schema(): array;
abstract public function execute( $input );         // returns mixed or WP_Error
```

### `Ability_Factory`

A plain keyed collection. You `add()` ability instances to it; the orchestrator reads them via `all()`.

### `Ability_Orchestrator`

Owns the factory. Call `add()` to load abilities, then `register_all()` once — it hooks into `wp_abilities_api_categories_init` and `wp_abilities_api_init` and registers everything in the factory.

### `Block_Serializer`

A pure static class with no side-effects. `Block_Serializer::serialize( array $blocks ): string` iterates a decoded JSON block array, converts each descriptor to a Gutenberg block via `serialize_block()`, validates content with `wp_kses_post()`, logs and skips unrecognized or malformed items, and returns the concatenated `post_content` string.

---

## Post-generation pipeline

`Write_Post_Ability::execute()` runs three sequential steps. The save logic (step 3) is isolated and never changes regardless of how the content is produced.

```
Input: { title, notes, max_words }
        │
        ▼
Step 1  generate_content()
        Ask the AI for a JSON array of block descriptors.
        Format instructions come before the writing task in the prompt
        so the model commits to JSON output before reading the task.
        max_tokens = max_words × 2  (headroom for JSON structural keys)
        │
        ▼
Step 2  json_to_blocks()  →  Block_Serializer::serialize()
        Strip any markdown code fences the AI may have added.
        json_decode() the cleaned string.
        Convert each block descriptor to serialized Gutenberg markup.
        Unknown block types are logged and skipped — the post still saves.
        │
        ▼
Step 3  wp_insert_post()
        Save the serialized string as a draft. Unchanged.
```

### Block descriptor format

The AI is instructed to return an array using these shapes:

```json
[
  { "type": "core/heading",   "level": 2, "content": "Heading text" },
  { "type": "core/paragraph", "content": "Paragraph text." },
  { "type": "core/list",      "ordered": false, "items": ["One", "Two"] },
  { "type": "core/quote",     "content": "Quote text.", "citation": "Author" },
  { "type": "core/image",     "url": "https://...", "alt": "Description" }
]
```

---

## Post length and the timeout constraint

The WordPress AI Client makes a **non-streaming** request: the provider generates the full response before sending the first byte. A long post can take 40–60 seconds, which exceeds the default 30-second cURL timeout — arriving as a malformed or empty response.

### How this plugin handles it

`max_tokens` is calculated dynamically from the user-supplied word count:

```
max_tokens = max_words × 2
```

The factor of 2 accounts for JSON structural overhead (`"type"`, `"content"`, `"items"`, etc.) that wraps the actual prose. The word limit is also injected into the prompt itself so the model stops before hitting the token ceiling rather than being cut off mid-JSON.

| Word target | `max_tokens` | Approx. generation time |
|---|---|---|
| 100 | 200 | ~3s |
| 300 (default) | 600 | ~10s |
| 500 | 1000 | ~17s ⚠ |
| 700 (max) | 1400 | ~23s ⚠ |

The dialog shows a warning when the slider exceeds 500 words. If you hit timeouts above that threshold, consider splitting the work across multiple ability calls or enabling streaming if your WordPress AI Client version supports it.

---

## How to add a new ability

### 1. Create the class

Add a file to `includes/abilities/`. Extend `Ability` and implement all abstract methods.

```php
<?php

namespace WP_Abilities_Test\Abilities;

use WP_Abilities_Test\Ability;

class Summarize_Post_Ability extends Ability {

    public function get_name(): string {
        return 'wp-abilities-api-test/summarize-post';
    }

    public function get_label(): string {
        return __( 'Summarize Post', 'wp-abilities-api-test' );
    }

    public function get_description(): string {
        return __( 'Returns a short summary of a post given its ID.', 'wp-abilities-api-test' );
    }

    public function get_category(): string {
        return 'content'; // must match a registered category slug
    }

    public function get_input_schema(): array {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'post_id' => array(
                    'type'        => 'integer',
                    'description' => 'The ID of the post to summarize.',
                ),
            ),
            'required'             => array( 'post_id' ),
            'additionalProperties' => false,
        );
    }

    public function get_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'summary' => array(
                    'type'        => 'string',
                    'description' => 'A short summary of the post.',
                ),
            ),
        );
    }

    public function execute( $input ) {
        $post = get_post( (int) ( $input['post_id'] ?? 0 ) );

        if ( ! $post ) {
            return new \WP_Error( 'not_found', 'Post not found.' );
        }

        $prompt  = sprintf( 'Summarize this post in two sentences: %s', wp_strip_all_tags( $post->post_content ) );
        $builder = \WordPress\AI\get_ai_service()->create_textgen_prompt(
            $prompt,
            array( 'max_tokens' => 120 )
        );

        $result = $builder->generate_text();

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return array( 'summary' => (string) $result );
    }
}
```

### 2. Register it in the main plugin file

Open `wp-abilities-api-test.php` and add one line inside the `plugins_loaded` callback:

```php
add_action(
    'plugins_loaded',
    static function () {
        $orchestrator = new Ability_Orchestrator();
        $orchestrator->add( new Write_Post_Ability() );
        $orchestrator->add( new Serialize_Blocks_Ability() );
        $orchestrator->add( new Summarize_Post_Ability() ); // ← add this
        $orchestrator->register_all();
    }
);
```

That is all. The new ability is now:

- Registered in the Abilities API registry.
- Discoverable by AI agents via the REST API (because `show_in_rest` is `true` in the base class).
- Executable via the plugin's REST endpoint (see below).

### 3. Add a new category (optional)

If your ability belongs to a new category, add it in `Ability_Orchestrator::register_categories()`:

```php
public function register_categories(): void {
    wp_register_ability_category( 'content', array( /* ... */ ) );

    wp_register_ability_category(
        'seo',
        array(
            'label'       => __( 'SEO', 'wp-abilities-api-test' ),
            'description' => __( 'Abilities that assist with search engine optimisation.', 'wp-abilities-api-test' ),
        )
    );
}
```

---

## REST endpoint

The plugin exposes a single endpoint for executing any registered ability:

```
POST /wp-json/wp-abilities-test/v1/execute
```

**Request body:**

```json
{
  "ability": "wp-abilities-api-test/write-post",
  "input": {
    "title": "10 Tips for Better Sleep",
    "notes": "Focus on natural remedies, avoid medication advice.",
    "max_words": 300
  }
}
```

`max_words` is optional and defaults to `300`. Accepted range: `100`–`700`.

**Successful response:**

```json
{
  "post_id": 42,
  "post_url": "https://example.com/wp-admin/post.php?post=42&action=edit",
  "title": "10 Tips for Better Sleep"
}
```

The `serialize-blocks` ability can also be called directly:

```json
{
  "ability": "wp-abilities-api-test/serialize-blocks",
  "input": {
    "blocks": [
      { "type": "core/heading", "level": 2, "content": "My Heading" },
      { "type": "core/paragraph", "content": "My paragraph text." }
    ]
  }
}
```

Authentication: the current user must have the `edit_posts` capability.

---

## How the Abilities API works (quick reference)

Abilities are registered on the `wp_abilities_api_init` hook using `wp_register_ability()`:

```php
add_action( 'wp_abilities_api_init', function () {
    wp_register_ability(
        'my-plugin/my-ability',         // namespace/name
        array(
            'label'               => 'My Ability',
            'description'         => 'Does something useful.',
            'category'            => 'my-category',
            'input_schema'        => array( /* JSON Schema */ ),
            'output_schema'       => array( /* JSON Schema */ ),
            'execute_callback'    => 'my_ability_callback',
            'permission_callback' => '__return_true',
            'meta'                => array( 'show_in_rest' => true ),
        )
    );
} );
```

Categories are registered on `wp_abilities_api_categories_init` using `wp_register_ability_category()`.

Retrieve and execute an ability anywhere in PHP:

```php
$ability = wp_get_ability( 'my-plugin/my-ability' );
$result  = $ability->execute( $input ); // returns mixed or WP_Error
```

Full documentation: [developer.wordpress.org/apis/abilities-api](https://developer.wordpress.org/apis/abilities-api/)

---

## License

GPLv2 or later — same as WordPress itself.
