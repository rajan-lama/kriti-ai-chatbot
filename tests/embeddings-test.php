<?php
/**
 * Tests for the embedding capability added to the Kriti AI plugin: its own providers in the Integrations Hub
 * (OpenAI, Gemini, Ollama embeddings) and the mock.
 *
 *   wp eval-file wp-content/plugins/kriti-ai-chatbot/tests/embeddings-test.php
 *
 * HTTP is mocked at WordPress' `pre_http_request` filter, so every provider's real request and response
 * shape is exercised without a key and without a network.
 *
 * @package KritiAIChatbot
 */

use KritiAI\Provider_Manager;

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'KritiAI\\Provider_Manager' ) ) {
	echo "Run this with wp eval-file, with the Kriti AI plugin active.\n";
	exit( 1 );
}

$GLOBALS['kai_emb_pass'] = 0;
$GLOBALS['kai_emb_fail'] = 0;
$GLOBALS['kai_emb_seen'] = array();

/**
 * Assertion helper.
 *
 * @param bool   $ok   Condition.
 * @param string $name Name.
 * @param string $note Detail on failure.
 */
function kai_emb_ok( $ok, $name, $note = '' ) {
	if ( $ok ) {
		++$GLOBALS['kai_emb_pass'];
		echo "  ok   {$name}\n";
	} else {
		++$GLOBALS['kai_emb_fail'];
		echo "  FAIL {$name}" . ( '' !== $note ? " -- {$note}" : '' ) . "\n";
	}
}

/**
 * Cosine similarity of two vectors.
 *
 * @param float[] $a A.
 * @param float[] $b B.
 * @return float
 */
function kai_emb_cos( array $a, array $b ) {
	$dot = 0.0;
	$na  = 0.0;
	$nb  = 0.0;

	foreach ( $a as $i => $x ) {
		$dot += $x * $b[ $i ];
		$na  += $x * $x;
		$nb  += $b[ $i ] * $b[ $i ];
	}

	return $na > 0 && $nb > 0 ? $dot / sqrt( $na * $nb ) : 0.0;
}

/**
 * Serve a canned HTTP response and record the request.
 *
 * @param int   $code Status.
 * @param array $body Body to JSON-encode.
 * @return callable
 */
function kai_emb_http( $code, array $body ) {
	return static function ( $pre, $args, $url ) use ( $code, $body ) {
		$GLOBALS['kai_emb_seen'] = array(
			'url'     => $url,
			'headers' => isset( $args['headers'] ) ? $args['headers'] : array(),
			'body'    => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null,
		);

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
}

echo "Mock provider\n";

$mock = Provider_Manager::create( 'mock', array() );
kai_emb_ok( $mock instanceof KritiAI\Core\Embedding_Provider, 'implements the embedding interface' );

$r = $mock->generate_embeddings( array( 'the agency licence covers thirty websites', 'the agency licence covers thirty websites', 'agency licence for websites', 'banana smoothie recipe with oats' ), array() );
kai_emb_ok( is_array( $r ) && 4 === count( $r['vectors'] ) && 64 === $r['dimensions'] && 64 === count( $r['vectors'][0] ), 'one 64-dimension vector per text' );
kai_emb_ok( $r['vectors'][0] === $r['vectors'][1], 'equal texts give equal vectors (deterministic)' );
kai_emb_ok( kai_emb_cos( $r['vectors'][0], $r['vectors'][2] ) > kai_emb_cos( $r['vectors'][0], $r['vectors'][3] ), 'texts sharing words are closer than unrelated ones' );

$norm = sqrt( array_sum( array_map( static function ( $x ) {
	return $x * $x;
}, $r['vectors'][0] ) ) );
kai_emb_ok( abs( $norm - 1.0 ) < 1e-9, 'vectors are unit length' );

echo "OpenAI\n";

$openai = Provider_Manager::create( 'openai_embedding', array( 'api_key' => 'sk-test' ) );
kai_emb_ok( $openai instanceof KritiAI\Core\Embedding_Provider, 'implements the embedding interface' );

add_filter(
	'pre_http_request',
	$f = kai_emb_http(
		200,
		array(
			'data'  => array(
				array( 'index' => 1, 'embedding' => array( 0.3, 0.4 ) ),
				array( 'index' => 0, 'embedding' => array( 0.1, 0.2 ) ),
			),
			'usage' => array( 'prompt_tokens' => 9 ),
		)
	),
	10,
	3
);
$r = $openai->generate_embeddings( array( 'first', 'second' ), array() );
remove_filter( 'pre_http_request', $f, 10 );
$seen = $GLOBALS['kai_emb_seen'];

kai_emb_ok( 'https://api.openai.com/v1/embeddings' === $seen['url'], 'calls /v1/embeddings' );
kai_emb_ok( 'Bearer sk-test' === $seen['headers']['Authorization'], 'sends the key as a bearer token' );
kai_emb_ok( 'text-embedding-3-small' === $seen['body']['model'] && array( 'first', 'second' ) === $seen['body']['input'], 'sends the default model and all texts in one request' );
kai_emb_ok( is_array( $r ) && array( array( 0.1, 0.2 ), array( 0.3, 0.4 ) ) === $r['vectors'], 'vectors are put back in input order' );
kai_emb_ok( is_array( $r ) && 2 === $r['dimensions'] && 9 === $r['tokens_in'] && 'text-embedding-3-small' === $r['model'], 'dimensions, tokens and model are reported' );

add_filter( 'pre_http_request', $f = kai_emb_http( 200, array( 'data' => array( array( 'index' => 0, 'embedding' => array( 1.0 ) ) ) ) ), 10, 3 );
$r = $openai->generate_embeddings( array( 'a', 'b' ), array() );
remove_filter( 'pre_http_request', $f, 10 );
kai_emb_ok( is_wp_error( $r ), 'fewer vectors than texts is an error, not silent truncation' );

add_filter( 'pre_http_request', $f = kai_emb_http( 401, array( 'error' => array( 'message' => 'Incorrect API key provided: sk-test.' ) ) ), 10, 3 );
$r = $openai->generate_embeddings( array( 'a' ), array() );
remove_filter( 'pre_http_request', $f, 10 );
kai_emb_ok( is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'Incorrect API key' ), 'a provider error is passed on as a WP_Error' );

add_filter( 'pre_http_request', $f = kai_emb_http( 200, array( 'data' => array( array( 'index' => 0, 'embedding' => array( 1.0 ) ) ) ) ), 10, 3 );
$openai->generate_embeddings( array( 'a' ), array( 'model' => 'text-embedding-3-large', 'dimensions' => 256 ) );
remove_filter( 'pre_http_request', $f, 10 );
kai_emb_ok( 'text-embedding-3-large' === $GLOBALS['kai_emb_seen']['body']['model'] && 256 === $GLOBALS['kai_emb_seen']['body']['dimensions'], 'model and dimensions can be set per request' );

kai_emb_ok( is_wp_error( $openai->generate_embeddings( array(), array() ) ), 'nothing to embed is an error' );

echo "Gemini\n";

$gemini = Provider_Manager::create( 'gemini_embedding', array( 'api_key' => 'g-test' ) );
kai_emb_ok( $gemini instanceof KritiAI\Core\Embedding_Provider, 'implements the embedding interface' );

add_filter( 'pre_http_request', $f = kai_emb_http( 200, array( 'embeddings' => array( array( 'values' => array( 0.5, 0.6 ) ), array( 'values' => array( 0.7, 0.8 ) ) ) ) ), 10, 3 );
$r = $gemini->generate_embeddings( array( 'one', 'two' ), array() );
remove_filter( 'pre_http_request', $f, 10 );
$seen = $GLOBALS['kai_emb_seen'];

kai_emb_ok( false !== strpos( $seen['url'], '/v1beta/models/gemini-embedding-001:batchEmbedContents?key=g-test' ), 'calls batchEmbedContents with the default model' );
kai_emb_ok( 2 === count( $seen['body']['requests'] ) && 'models/gemini-embedding-001' === $seen['body']['requests'][0]['model'] && 'two' === $seen['body']['requests'][1]['content']['parts'][0]['text'], 'sends one request entry per text' );
kai_emb_ok( is_array( $r ) && array( array( 0.5, 0.6 ), array( 0.7, 0.8 ) ) === $r['vectors'] && 2 === $r['dimensions'], 'parses the vectors' );

echo "Ollama\n";

$ollama = Provider_Manager::create( 'ollama_embedding', array( 'base_url' => 'http://localhost:11434' ) );
kai_emb_ok( $ollama instanceof KritiAI\Core\Embedding_Provider, 'implements the embedding interface' );

add_filter( 'pre_http_request', $f = kai_emb_http( 200, array( 'embeddings' => array( array( 0.1, 0.9 ) ), 'prompt_eval_count' => 4 ) ), 10, 3 );
$r = $ollama->generate_embeddings( array( 'hello' ), array() );
remove_filter( 'pre_http_request', $f, 10 );
$seen = $GLOBALS['kai_emb_seen'];
kai_emb_ok( 'http://localhost:11434/api/embed' === $seen['url'] && 'nomic-embed-text' === $seen['body']['model'], 'calls /api/embed with the default model' );
kai_emb_ok( is_array( $r ) && array( array( 0.1, 0.9 ) ) === $r['vectors'] && 4 === $r['tokens_in'], 'parses the vectors and the token count' );

add_filter( 'pre_http_request', $f = kai_emb_http( 404, array( 'error' => 'model "nomic-embed-text" not found, try pulling it first' ) ), 10, 3 );
$r = $ollama->generate_embeddings( array( 'hello' ), array() );
remove_filter( 'pre_http_request', $f, 10 );
kai_emb_ok( is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'ollama pull nomic-embed-text' ), 'a missing model tells the admin the exact command to run' );

echo "Integrations Hub wiring\n";

kai_emb_ok( array( 'openai_embedding', 'gemini_embedding', 'ollama_embedding', 'mock' ) === Provider_Manager::slugs_for( 'embedding' ), 'embedding is a capability with its own provider slugs' );
kai_emb_ok( ! array_intersect( array( 'openai', 'gemini', 'ollama' ), Provider_Manager::slugs_for( 'embedding' ) ), 'it does not borrow the text providers: each has its own card and its own key' );
kai_emb_ok( Provider_Manager::requires_key( 'openai_embedding' ) && Provider_Manager::requires_key( 'gemini_embedding' ) && ! Provider_Manager::requires_key( 'ollama_embedding' ), 'OpenAI and Gemini need a key, Ollama needs a server address' );
kai_emb_ok( Provider_Manager::has_model_setting( 'openai_embedding' ) && Provider_Manager::has_model_setting( 'ollama_embedding' ) && ! Provider_Manager::has_model_setting( 'openai_image' ), 'embedding cards carry a model field; other cloud cards do not' );
kai_emb_ok( 'OpenAI (Embeddings)' === Provider_Manager::label( 'openai_embedding' ) && 'Ollama (Embeddings)' === Provider_Manager::label( 'ollama_embedding' ), 'the cards have readable names' );
kai_emb_ok( 'text-embedding-3-small' === Provider_Manager::model_choices( 'openai_embedding' )[0] && 'nomic-embed-text' === Provider_Manager::model_choices( 'ollama_embedding' )[0], 'each card suggests models' );
kai_emb_ok( Provider_Manager::is_embedding( 'gemini_embedding' ) && ! Provider_Manager::is_embedding( 'gemini' ), 'embedding slugs are recognised by their suffix' );
kai_emb_ok( array_key_exists( 'default_embedding_provider', KritiAI\Settings::get() ), 'there is a default-provider setting for embeddings, like the other capabilities' );

// The model comes from the card; with none saved the first suggestion is used.
kai_emb_ok( 'text-embedding-3-small' === Provider_Manager::default_model( 'openai_embedding', array() ), 'no saved model: the first suggested model is used' );
kai_emb_ok( 'text-embedding-3-large' === Provider_Manager::default_model( 'openai_embedding', array( 'model' => 'text-embedding-3-large' ) ), 'a model saved on the card wins' );
kai_emb_ok( '' === Provider_Manager::default_model( 'ollama', array() ), 'text providers are unchanged: Ollama text still guesses no model' );
kai_emb_ok( 'gpt-4o' === Provider_Manager::default_model( 'openai', array() ), 'and OpenAI text still defaults to its first model' );

$configured = Provider_Manager::create( 'openai_embedding', array( 'api_key' => 'sk-test', 'model' => 'text-embedding-3-large' ) );
add_filter( 'pre_http_request', $f = kai_emb_http( 200, array( 'data' => array( array( 'index' => 0, 'embedding' => array( 1.0 ) ) ) ) ), 10, 3 );
$configured->generate_embeddings( array( 'a' ), array() );
remove_filter( 'pre_http_request', $f, 10 );
kai_emb_ok( 'text-embedding-3-large' === $GLOBALS['kai_emb_seen']['body']['model'], 'a request uses the model saved on the card' );

add_filter( 'kriti_ai_embedding_model', $mf = static function () {
	return 'custom-model';
} );
kai_emb_ok( 'custom-model' === Provider_Manager::default_embedding_model( 'openai_embedding' ), 'the model can still be filtered' );
remove_filter( 'kriti_ai_embedding_model', $mf );

echo "Test connection (the Hub button) checks the model, not just the server\n";

// OpenAI: asks for the configured model itself.
add_filter( 'pre_http_request', $f = kai_emb_http( 200, array( 'id' => 'text-embedding-3-small' ) ), 10, 3 );
kai_emb_ok( true === $openai->test_connection() && 'https://api.openai.com/v1/models/text-embedding-3-small' === $GLOBALS['kai_emb_seen']['url'], 'OpenAI: a good key and an existing model pass' );
remove_filter( 'pre_http_request', $f, 10 );
add_filter( 'pre_http_request', $f = kai_emb_http( 404, array( 'error' => array( 'message' => "The model 'text-embedding-9' does not exist" ) ) ), 10, 3 );
$bad = Provider_Manager::create( 'openai_embedding', array( 'api_key' => 'sk-test', 'model' => 'text-embedding-9' ) )->test_connection();
remove_filter( 'pre_http_request', $f, 10 );
kai_emb_ok( is_wp_error( $bad ) && false !== strpos( $bad->get_error_message(), 'text-embedding-9' ) && false !== strpos( $GLOBALS['kai_emb_seen']['url'], '/v1/models/text-embedding-9' ), 'OpenAI: a good key with a wrong model name fails, naming the model' );

// Gemini: asks for the configured model itself.
add_filter( 'pre_http_request', $f = kai_emb_http( 200, array( 'name' => 'models/gemini-embedding-001' ) ), 10, 3 );
kai_emb_ok( true === $gemini->test_connection() && false !== strpos( $GLOBALS['kai_emb_seen']['url'], '/v1beta/models/gemini-embedding-001?key=g-test' ), 'Gemini: a good key and an existing model pass' );
remove_filter( 'pre_http_request', $f, 10 );
add_filter( 'pre_http_request', $f = kai_emb_http( 404, array( 'error' => array( 'message' => 'models/nope is not found' ) ) ), 10, 3 );
$bad = Provider_Manager::create( 'gemini_embedding', array( 'api_key' => 'g-test', 'model' => 'nope' ) )->test_connection();
remove_filter( 'pre_http_request', $f, 10 );
kai_emb_ok( is_wp_error( $bad ) && false !== strpos( $bad->get_error_message(), 'not found' ), 'Gemini: a good key with a wrong model name fails' );

// Ollama: the server answering is not enough; the model must be installed.
$installed = static function ( array $names ) {
	return kai_emb_http( 200, array( 'models' => array_map( static function ( $n ) {
		return array( 'name' => $n );
	}, $names ) ) );
};

add_filter( 'pre_http_request', $f = $installed( array( 'qwen3.5:latest', 'x/flux2-klein:latest' ) ), 10, 3 );
$missing = $ollama->test_connection();
remove_filter( 'pre_http_request', $f, 10 );
kai_emb_ok( is_wp_error( $missing ) && 'kriti_ai_model_missing' === $missing->get_error_code() && false !== strpos( $missing->get_error_message(), 'ollama pull nomic-embed-text' ), 'Ollama: server up but the model missing is NOT "connected"; it says what to run' );
kai_emb_ok( 'http://localhost:11434/api/tags' === $GLOBALS['kai_emb_seen']['url'], '(it asks the server which models it has)' );

add_filter( 'pre_http_request', $f = $installed( array( 'qwen3.5:latest', 'nomic-embed-text:latest' ) ), 10, 3 );
kai_emb_ok( true === $ollama->test_connection(), 'Ollama: a bare model name matches the installed ":latest" model' );
remove_filter( 'pre_http_request', $f, 10 );

add_filter( 'pre_http_request', $f = $installed( array( 'mxbai-embed-large:335m' ) ), 10, 3 );
kai_emb_ok( true === Provider_Manager::create( 'ollama_embedding', array( 'base_url' => 'http://localhost:11434', 'model' => 'mxbai-embed-large:335m' ) )->test_connection(), 'Ollama: a model with an explicit tag matches exactly' );
kai_emb_ok( is_wp_error( Provider_Manager::create( 'ollama_embedding', array( 'base_url' => 'http://localhost:11434', 'model' => 'mxbai-embed-large' ) )->test_connection() ), 'Ollama: but a bare name does not match a different tag' );
remove_filter( 'pre_http_request', $f, 10 );

add_filter( 'pre_http_request', $f = static function () {
	return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
}, 10, 3 );
$down = $ollama->test_connection();
remove_filter( 'pre_http_request', $f, 10 );
kai_emb_ok( is_wp_error( $down ) && 'kriti_ai_connection_failed' === $down->get_error_code(), 'Ollama: a server that is down is reported as a connection failure, not a missing model' );

add_filter( 'pre_http_request', $f = kai_emb_http( 200, array( 'models' => array() ) ), 10, 3 );
kai_emb_ok( is_wp_error( $ollama->test_connection() ), 'Ollama: a server with no models installed fails the test' );
remove_filter( 'pre_http_request', $f, 10 );

kai_emb_ok( is_wp_error( Provider_Manager::create( 'openai_embedding', array() )->test_connection() ), 'without a key the connection test says so' );

// Enabled automatically, like the other cards: a key (or a server address) switches it on.
kai_emb_ok( Provider_Manager::create( 'openai_embedding', array( 'api_key' => 'k' ) )->is_enabled() && ! Provider_Manager::create( 'openai_embedding', array() )->is_enabled(), 'a card is enabled by saving its key' );
kai_emb_ok( Provider_Manager::create( 'ollama_embedding', array( 'base_url' => 'http://localhost:11434' ) )->is_enabled() && ! Provider_Manager::create( 'ollama_embedding', array() )->is_enabled(), 'the Ollama card is enabled by its server address' );

echo "The chatbot uses what the Hub provides\n";

// Provider instances are cached for the request; start from a clean cache so the options below are read.
$reset_cache = static function () {
	$prop = new ReflectionProperty( 'KritiAI\\Provider_Manager', 'instances' );
	$prop->setAccessible( true );
	$prop->setValue( null, array() );
};

$saved_providers = get_option( 'kriti_ai_providers', null );
$saved_kriti     = get_option( 'kriti_ai_settings', null );
$saved_chatbot   = get_option( 'kai_chatbot_settings', null );

update_option( 'kriti_ai_providers', array(
	'openai_embedding' => array( 'enabled' => true, 'api_key' => 'sk-a', 'model' => 'text-embedding-3-large' ),
	'ollama_embedding' => array( 'enabled' => true, 'api_key' => '', 'model' => 'mxbai-embed-large', 'base_url' => 'http://localhost:11434' ),
) );
update_option( 'kriti_ai_settings', array_merge( (array) $saved_kriti, array( 'default_embedding_provider' => 'ollama_embedding' ) ) );
update_option( 'kai_chatbot_settings', array( 'embeddings' => 1, 'embedding_provider' => '', 'embedding_model' => '' ) );
KritiAIChatbot\Settings::flush();
$reset_cache();

kai_emb_ok( KritiAIChatbot\Embedder::ready(), 'the chatbot is ready once an embedding card is configured in the Hub' );
kai_emb_ok( 'ollama_embedding' === KritiAIChatbot\Embedder::provider(), 'it follows the default embedding provider chosen in Kriti AI → Settings, not just the first one' );
kai_emb_ok( 'mxbai-embed-large' === KritiAIChatbot\Embedder::model(), 'and the model saved on that provider\'s Hub card' );

update_option( 'kriti_ai_settings', array_merge( (array) $saved_kriti, array( 'default_embedding_provider' => '' ) ) );
$reset_cache();
kai_emb_ok( 'openai_embedding' === KritiAIChatbot\Embedder::provider(), 'with no default chosen it uses the first configured card' );

update_option( 'kai_chatbot_settings', array( 'embeddings' => 1, 'embedding_provider' => 'ollama_embedding', 'embedding_model' => 'bge-m3' ) );
KritiAIChatbot\Settings::flush();
$reset_cache();
kai_emb_ok( 'ollama_embedding' === KritiAIChatbot\Embedder::provider() && 'bge-m3' === KritiAIChatbot\Embedder::model(), 'a choice made in the chatbot settings overrides the Hub default for the chatbot only' );

update_option( 'kai_chatbot_settings', array( 'embeddings' => 1, 'embedding_provider' => 'gemini_embedding', 'embedding_model' => '' ) );
KritiAIChatbot\Settings::flush();
$reset_cache();
kai_emb_ok( 'ollama_embedding' === KritiAIChatbot\Embedder::provider() || 'openai_embedding' === KritiAIChatbot\Embedder::provider(), 'a provider that is not configured in the Hub is ignored, not trusted' );

update_option( 'kriti_ai_providers', array() );
update_option( 'kai_chatbot_settings', array( 'embeddings' => 1 ) );
KritiAIChatbot\Settings::flush();
$reset_cache();
kai_emb_ok( ! KritiAIChatbot\Embedder::ready() && '' === KritiAIChatbot\Embedder::provider(), 'with no card configured the chatbot falls back to keyword search' );

foreach ( array( 'kriti_ai_providers' => $saved_providers, 'kriti_ai_settings' => $saved_kriti, 'kai_chatbot_settings' => $saved_chatbot ) as $name => $value ) {
	null === $value ? delete_option( $name ) : update_option( $name, $value );
}
KritiAIChatbot\Settings::flush();
$reset_cache();

echo "Provider manager\n";

kai_emb_ok( ! in_array( 'anthropic', Provider_Manager::slugs_for( 'embedding' ), true ), 'providers without embeddings are not offered' );

// Through the manager, with the site's configured provider and a mocked response.
$enabled = Provider_Manager::enabled_slugs( 'embedding' );
if ( $enabled && 'mock' !== $enabled[0] ) {
	add_filter( 'pre_http_request', $f = kai_emb_http( 200, array( 'embeddings' => array( array( 0.2, 0.8 ) ), 'data' => array( array( 'index' => 0, 'embedding' => array( 0.2, 0.8 ) ) ) ) ), 10, 3 );
	$r = Provider_Manager::embed( array( 'hello' ) );
	remove_filter( 'pre_http_request', $f, 10 );
	kai_emb_ok( is_array( $r ) && $r['provider'] === $enabled[0], 'embed() uses the site default provider and says which one', is_wp_error( $r ) ? $r->get_error_message() : '' );
} else {
	echo "  skip  no real embedding provider is configured on this site\n";
}

// Provider instances are cached per request, so the capability is narrowed with the manager's own filter.
add_filter( 'kriti_ai_providers_for_capability', $only_mock = static function ( $slugs, $capability ) {
	return 'embedding' === $capability ? array( 'mock' ) : $slugs;
}, 10, 2 );
$r = Provider_Manager::embed( array( 'hello' ) );
remove_filter( 'kriti_ai_providers_for_capability', $only_mock, 10 );
kai_emb_ok( is_array( $r ) && 'mock' === $r['provider'], 'with nothing else configured the mock provider is used' );

add_filter( 'kriti_ai_providers_for_capability', $none = static function ( $slugs, $capability ) {
	return 'embedding' === $capability ? array() : $slugs;
}, 10, 2 );
$r = Provider_Manager::embed( array( 'hello' ) );
remove_filter( 'kriti_ai_providers_for_capability', $none, 10 );
kai_emb_ok( is_wp_error( $r ) && 'kriti_ai_no_embedding_provider' === $r->get_error_code(), 'with no provider at all the error says what to set up' );

echo "\n{$GLOBALS['kai_emb_pass']} passed, {$GLOBALS['kai_emb_fail']} failed\n";
exit( $GLOBALS['kai_emb_fail'] ? 1 : 0 );
