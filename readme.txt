=== Kriti AI Chatbot ===
Contributors: lamarajan
Tags: ai, chatbot, rag, knowledge base, easy digital downloads
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A chatbot that answers visitors' questions from your own content, shows where the answer came from, and says so when it does not know.

== Description ==

Kriti AI Chatbot reads the published pages of your site and answers visitors' questions from them. It does not train a model: when you change a page, the page is indexed again, and the next answer uses the new text.

* Answers come only from your content, with the pages they are based on shown as sources.
* When your content has no answer, it says so instead of guessing, and the question is listed on the dashboard so you know what to write next.
* Visitors can rate an answer. Ratings and unanswered questions are signals for you to review; they never become knowledge by themselves.
* Posts, pages, custom post types and Easy Digital Downloads products. For downloads, licence options are indexed and prices are always read live from the store.
* Leave pages out by ID, by part of the address, by category or tag, or with a custom field.
* Drafts, private and password-protected pages are never used, and a page that stops being public stops being used at once.
* Updates itself a few seconds after you save a page, retries with a growing wait when a provider fails, and checks the whole site once a day for anything it missed.
* No names, email addresses or IP addresses are stored. You choose how long conversations are kept, or not to keep them.
* A button that opens the chat can be placed anywhere with the `[kriti_ai_chatbot]` shortcode or the "Chatbot button" block.

= How it finds and writes answers =

It uses the AI providers you already set up in the Kriti AI plugin; it holds no keys of its own.

* **Keyword search** always works.
* **Meaning search** (embeddings) is added when an embedding provider is set up in Kriti AI → Integrations Hub (OpenAI, Gemini or Ollama). Each passage of your pages is turned into numbers when it is indexed, and the two searches are combined. Vectors are stored in your own database; no external vector service is needed.
* Without a text provider it shows the best matching passage instead of a written answer.

= Current limits =

* The built-in vector store compares passages one by one and is meant for a few thousand passages. It tells you when a site outgrows it.
* Answers are not streamed; they appear when complete.
* One chatbot per site.
* How close in meaning a passage must be before the chatbot answers depends on the embedding model. Test with your own questions and adjust "Minimum meaning match".

== Installation ==

1. Upload the plugin and activate it.
2. Open Kriti Chatbot → Knowledge Sources, choose your content and press Sync now.
3. In Kriti AI → Integrations Hub, add a text provider for written answers and an embedding provider (OpenAI, Gemini, or Ollama after `ollama pull nomic-embed-text`) for meaning search.

== Privacy ==

Questions and answers are stored on your site for the period you choose (Kriti Chatbot → Settings). The text of a question and the most relevant passages of your content are sent to the AI provider you picked in Kriti AI. With meaning search on, the text of your published pages is also sent to the embedding provider when pages are indexed; switch it off in Settings to prevent that. Suggested privacy policy text is added under Settings → Privacy.

The visitor's own browser also keeps the conversation (its id and the last 30 messages) in local storage for up to seven days, so the chat is the same on every page and on the next visit. It is not kept when "Do not store conversations" is chosen, and the visitor can clear it with the reset button.

Deleting the plugin keeps its data unless you tick "Also delete the index, conversations and settings" first.

== Changelog ==

= Unreleased =
* The chat now keeps its conversation and open/closed state across pages, new tabs and return visits (up to seven days) instead of only within one tab.

= 0.1.0 =
* First version.
