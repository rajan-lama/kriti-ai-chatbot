<?php
/**
 * Settings view.
 *
 * @package KritiAIChatbot
 *
 * @var array    $s         Settings.
 * @var string[] $providers Enabled Kriti AI text providers.
 * @var string[] $embedders Enabled Kriti AI embedding providers.
 */

defined( 'ABSPATH' ) || exit;

use KritiAIChatbot\Settings;
use KritiAIChatbot\Ui;

$name = Settings::OPTION;
?>
<div class="wrap kai-admin">
	<?php Ui::header( 'settings' ); ?>
	<h1 class="screen-reader-text"><?php esc_html_e( 'Chatbot Settings', 'kriti-ai-chatbot' ); ?></h1>

	<?php Ui::title( __( 'Settings', 'kriti-ai-chatbot' ), __( 'How the chatbot answers, how it looks, and what it keeps.', 'kriti-ai-chatbot' ) ); ?>

	<form method="post" action="options.php">
		<?php settings_fields( 'kai_chatbot' ); ?>

		<?php Ui::panel_open( 'tune', __( 'General', 'kriti-ai-chatbot' ), __( 'Switch the chatbot on and choose which AI writes the answers.', 'kriti-ai-chatbot' ) ); ?>
			<div class="kai-fields">
				<?php Ui::field_open( '', __( 'Chatbot', 'kriti-ai-chatbot' ) ); ?>
					<?php Ui::check( $name . '[enabled]', (bool) $s['enabled'], __( 'Show the chat button on the site', 'kriti-ai-chatbot' ), __( 'Turn off to hide the chatbot everywhere, including the shortcode and block.', 'kriti-ai-chatbot' ) ); ?>
				<?php Ui::field_close(); ?>

				<?php Ui::field_open( 'kai-provider', __( 'AI provider', 'kriti-ai-chatbot' ) ); ?>
					<select id="kai-provider" name="<?php echo esc_attr( $name ); ?>[provider]">
						<option value=""><?php esc_html_e( 'Kriti AI default', 'kriti-ai-chatbot' ); ?></option>
						<?php foreach ( $providers as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $s['provider'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php Ui::field_close( __( 'Writes the answers. Providers and keys are set up in Kriti AI → Integrations Hub.', 'kriti-ai-chatbot' ) ); ?>
			</div>
		<?php Ui::panel_close(); ?>

		<?php Ui::panel_open( 'psychology', __( 'AI instructions', 'kriti-ai-chatbot' ), __( 'Shape how the chatbot speaks. It always answers only from your content, whatever you write here.', 'kriti-ai-chatbot' ) ); ?>
			<div class="kai-fields">
				<?php Ui::field_open( 'kai-tone', __( 'Tone', 'kriti-ai-chatbot' ) ); ?>
					<select id="kai-tone" name="<?php echo esc_attr( $name ); ?>[tone]">
						<option value="professional" <?php selected( $s['tone'], 'professional' ); ?>><?php esc_html_e( 'Professional', 'kriti-ai-chatbot' ); ?></option>
						<option value="friendly" <?php selected( $s['tone'], 'friendly' ); ?>><?php esc_html_e( 'Friendly', 'kriti-ai-chatbot' ); ?></option>
						<option value="casual" <?php selected( $s['tone'], 'casual' ); ?>><?php esc_html_e( 'Casual', 'kriti-ai-chatbot' ); ?></option>
					</select>
				<?php Ui::field_close(); ?>

				<?php Ui::field_open( 'kai-length', __( 'Answer length', 'kriti-ai-chatbot' ) ); ?>
					<select id="kai-length" name="<?php echo esc_attr( $name ); ?>[length]">
						<option value="concise" <?php selected( $s['length'], 'concise' ); ?>><?php esc_html_e( 'Concise', 'kriti-ai-chatbot' ); ?></option>
						<option value="detailed" <?php selected( $s['length'], 'detailed' ); ?>><?php esc_html_e( 'Detailed', 'kriti-ai-chatbot' ); ?></option>
					</select>
				<?php Ui::field_close(); ?>

				<?php Ui::field_open( 'kai-uncertain', __( 'If it does not know', 'kriti-ai-chatbot' ) ); ?>
					<select id="kai-uncertain" name="<?php echo esc_attr( $name ); ?>[if_uncertain]">
						<option value="say_dont_know" <?php selected( $s['if_uncertain'], 'say_dont_know' ); ?>><?php esc_html_e( 'Say it does not know', 'kriti-ai-chatbot' ); ?></option>
						<option value="suggest_contact" <?php selected( $s['if_uncertain'], 'suggest_contact' ); ?>><?php esc_html_e( 'Say it does not know and show the contact text', 'kriti-ai-chatbot' ); ?></option>
					</select>
				<?php Ui::field_close(); ?>

				<?php Ui::field_open( 'kai-contact', __( 'Contact text', 'kriti-ai-chatbot' ) ); ?>
					<textarea id="kai-contact" name="<?php echo esc_attr( $name ); ?>[contact_text]" rows="2"><?php echo esc_textarea( $s['contact_text'] ); ?></textarea>
				<?php Ui::field_close( __( 'Shown when it does not know and the option on the left asks for it. For example: Please email support@example.com and we will help.', 'kriti-ai-chatbot' ) ); ?>

				<?php Ui::field_open( '', __( 'Citations', 'kriti-ai-chatbot' ), true ); ?>
					<?php Ui::check( $name . '[citations]', (bool) $s['citations'], __( 'Show the pages an answer is based on', 'kriti-ai-chatbot' ), __( 'Sources are only ever pages that were found for the question, never invented.', 'kriti-ai-chatbot' ) ); ?>
				<?php Ui::field_close(); ?>

				<?php Ui::field_open( 'kai-instructions', __( 'Your instructions', 'kriti-ai-chatbot' ), true ); ?>
					<textarea id="kai-instructions" name="<?php echo esc_attr( $name ); ?>[site_instructions]" rows="4"><?php echo esc_textarea( $s['site_instructions'] ); ?></textarea>
				<?php Ui::field_close( __( 'Added to the rules the chatbot already follows. It cannot make the chatbot ignore your content or invent facts.', 'kriti-ai-chatbot' ) ); ?>
			</div>
		<?php Ui::panel_close(); ?>

		<?php Ui::panel_open( 'palette', __( 'Appearance', 'kriti-ai-chatbot' ), __( 'What visitors see in the chat window.', 'kriti-ai-chatbot' ) ); ?>
			<div class="kai-fields">
				<?php Ui::field_open( 'kai-title', __( 'Title', 'kriti-ai-chatbot' ) ); ?>
					<input type="text" id="kai-title" name="<?php echo esc_attr( $name ); ?>[title]" value="<?php echo esc_attr( $s['title'] ); ?>" />
				<?php Ui::field_close(); ?>

				<?php Ui::field_open( 'kai-placeholder', __( 'Input hint', 'kriti-ai-chatbot' ) ); ?>
					<input type="text" id="kai-placeholder" name="<?php echo esc_attr( $name ); ?>[placeholder]" value="<?php echo esc_attr( $s['placeholder'] ); ?>" />
				<?php Ui::field_close(); ?>

				<?php Ui::field_open( 'kai-greeting', __( 'Greeting', 'kriti-ai-chatbot' ), true ); ?>
					<textarea id="kai-greeting" name="<?php echo esc_attr( $name ); ?>[greeting]" rows="2"><?php echo esc_textarea( $s['greeting'] ); ?></textarea>
				<?php Ui::field_close(); ?>

				<?php Ui::field_open( 'kai-color', __( 'Colour', 'kriti-ai-chatbot' ) ); ?>
					<input type="color" id="kai-color" name="<?php echo esc_attr( $name ); ?>[color]" value="<?php echo esc_attr( $s['color'] ); ?>" />
				<?php Ui::field_close( __( 'Pick a dark colour; text on it is white.', 'kriti-ai-chatbot' ) ); ?>

				<?php Ui::field_open( 'kai-position', __( 'Position', 'kriti-ai-chatbot' ) ); ?>
					<select id="kai-position" name="<?php echo esc_attr( $name ); ?>[position]">
						<option value="right" <?php selected( $s['position'], 'right' ); ?>><?php esc_html_e( 'Bottom right', 'kriti-ai-chatbot' ); ?></option>
						<option value="left" <?php selected( $s['position'], 'left' ); ?>><?php esc_html_e( 'Bottom left', 'kriti-ai-chatbot' ); ?></option>
					</select>
				<?php Ui::field_close(); ?>

				<?php Ui::field_open( 'kai-prompts', __( 'Suggested questions', 'kriti-ai-chatbot' ), true ); ?>
					<textarea id="kai-prompts" name="<?php echo esc_attr( $name ); ?>[suggested_prompts]" rows="3"><?php echo esc_textarea( $s['suggested_prompts'] ); ?></textarea>
				<?php Ui::field_close( __( 'Up to four, one per line. They appear as buttons before the first question.', 'kriti-ai-chatbot' ) ); ?>
			</div>
		<?php Ui::panel_close(); ?>

		<?php Ui::panel_open( 'manage_search', __( 'Retrieval', 'kriti-ai-chatbot' ), __( 'How passages are found and how sure the chatbot must be before it answers.', 'kriti-ai-chatbot' ) ); ?>
			<div class="kai-fields">
				<?php Ui::field_open( 'kai-topk', __( 'Passages per question', 'kriti-ai-chatbot' ) ); ?>
					<input type="number" id="kai-topk" min="1" max="12" name="<?php echo esc_attr( $name ); ?>[top_k]" value="<?php echo esc_attr( $s['top_k'] ); ?>" />
				<?php Ui::field_close(); ?>

				<?php Ui::field_open( 'kai-threshold', __( 'Minimum confidence', 'kriti-ai-chatbot' ) ); ?>
					<input type="number" id="kai-threshold" min="0" max="1" step="0.05" name="<?php echo esc_attr( $name ); ?>[confidence_threshold]" value="<?php echo esc_attr( $s['confidence_threshold'] ); ?>" />
				<?php Ui::field_close( __( 'Keyword evidence needed before the chatbot answers; below this it says it does not know instead of guessing. Higher is stricter.', 'kriti-ai-chatbot' ) ); ?>

				<?php Ui::field_open( 'kai-words', __( 'Passage size (words) and overlap', 'kriti-ai-chatbot' ), true ); ?>
					<div class="kai-inline">
						<input type="number" id="kai-words" min="100" max="1200" name="<?php echo esc_attr( $name ); ?>[chunk_words]" value="<?php echo esc_attr( $s['chunk_words'] ); ?>" />
						<label for="kai-overlap"><?php esc_html_e( 'Overlap', 'kriti-ai-chatbot' ); ?></label>
						<input type="number" id="kai-overlap" min="0" max="200" name="<?php echo esc_attr( $name ); ?>[chunk_overlap]" value="<?php echo esc_attr( $s['chunk_overlap'] ); ?>" />
					</div>
				<?php Ui::field_close( __( 'Applies the next time a page is indexed. Run a sync after changing it.', 'kriti-ai-chatbot' ) ); ?>
			</div>
		<?php Ui::panel_close(); ?>

		<?php Ui::panel_open( 'hub', __( 'Meaning search', 'kriti-ai-chatbot' ), __( 'Besides keywords, the chatbot can find passages by meaning. Each passage of your content is turned into numbers (an embedding) by an AI provider when it is indexed.', 'kriti-ai-chatbot' ) ); ?>
			<div class="kai-fields">
				<?php Ui::field_open( '', __( 'Meaning search', 'kriti-ai-chatbot' ), true ); ?>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>[embeddings_present]" value="1" />
					<?php Ui::check( $name . '[embeddings]', (bool) $s['embeddings'], __( 'Find passages by meaning as well as by keywords', 'kriti-ai-chatbot' ), __( 'This sends the text of your published pages to the provider chosen below. Switch it off to keep page text from being sent for this purpose.', 'kriti-ai-chatbot' ) ); ?>
				<?php Ui::field_close(); ?>

				<?php Ui::field_open( 'kai-emb-provider', __( 'Embedding provider', 'kriti-ai-chatbot' ) ); ?>
					<select id="kai-emb-provider" name="<?php echo esc_attr( $name ); ?>[embedding_provider]">
						<option value=""><?php esc_html_e( 'Kriti AI default', 'kriti-ai-chatbot' ); ?></option>
						<?php foreach ( $embedders as $slug ) : ?>
							<?php
							if ( 'mock' === $slug ) {
								continue;
							}
							?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $s['embedding_provider'], $slug ); ?>><?php echo esc_html( \KritiAI\Provider_Manager::label( $slug ) ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php Ui::field_close( __( 'Set up in Kriti AI → Integrations Hub, under “Embedding providers”. If you change the provider or model, run a sync: pages are embedded again.', 'kriti-ai-chatbot' ) ); ?>

				<?php Ui::field_open( 'kai-emb-model', __( 'Embedding model', 'kriti-ai-chatbot' ) ); ?>
					<input type="text" id="kai-emb-model" name="<?php echo esc_attr( $name ); ?>[embedding_model]" value="<?php echo esc_attr( $s['embedding_model'] ); ?>" placeholder="<?php esc_attr_e( 'Provider default', 'kriti-ai-chatbot' ); ?>" autocomplete="off" />
				<?php Ui::field_close( __( 'Leave empty to use the model saved on the provider’s card in the Integrations Hub. Fill it in only to override that model for the chatbot.', 'kriti-ai-chatbot' ) ); ?>

				<?php Ui::field_open( 'kai-vthreshold', __( 'Minimum meaning match', 'kriti-ai-chatbot' ) ); ?>
					<input type="number" id="kai-vthreshold" min="0" max="1" step="0.05" name="<?php echo esc_attr( $name ); ?>[vector_threshold]" value="<?php echo esc_attr( $s['vector_threshold'] ); ?>" />
				<?php Ui::field_close( __( 'How close in meaning a passage must be before the chatbot answers from it. Models differ a lot, so test with your own questions and adjust. Higher is stricter.', 'kriti-ai-chatbot' ) ); ?>
			</div>
		<?php Ui::panel_close(); ?>

		<?php Ui::panel_open( 'shield', __( 'Privacy and limits', 'kriti-ai-chatbot' ), __( 'No names, email addresses or IP addresses are stored with a conversation. Suggested privacy policy text is added under Settings → Privacy.', 'kriti-ai-chatbot' ) ); ?>
			<div class="kai-fields">
				<?php Ui::field_open( 'kai-retention', __( 'Keep conversations', 'kriti-ai-chatbot' ) ); ?>
					<select id="kai-retention" name="<?php echo esc_attr( $name ); ?>[retention_days]">
						<?php foreach ( Settings::retention_choices() as $days => $label ) : ?>
							<option value="<?php echo esc_attr( $days ); ?>" <?php selected( (int) $s['retention_days'], (int) $days ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php Ui::field_close(); ?>

				<?php Ui::field_open( 'kai-rate', __( 'Questions per minute, per visitor', 'kriti-ai-chatbot' ) ); ?>
					<input type="number" id="kai-rate" min="1" max="120" name="<?php echo esc_attr( $name ); ?>[rate_limit]" value="<?php echo esc_attr( $s['rate_limit'] ); ?>" />
				<?php Ui::field_close(); ?>

				<?php Ui::field_open( 'kai-chars', __( 'Longest question (characters)', 'kriti-ai-chatbot' ) ); ?>
					<input type="number" id="kai-chars" min="50" max="2000" name="<?php echo esc_attr( $name ); ?>[max_message_chars]" value="<?php echo esc_attr( $s['max_message_chars'] ); ?>" />
				<?php Ui::field_close(); ?>

				<?php Ui::field_open( 'kai-turns', __( 'Earlier turns the chatbot remembers', 'kriti-ai-chatbot' ) ); ?>
					<input type="number" id="kai-turns" min="0" max="10" name="<?php echo esc_attr( $name ); ?>[history_turns]" value="<?php echo esc_attr( $s['history_turns'] ); ?>" />
				<?php Ui::field_close(); ?>

				<?php Ui::field_open( '', __( 'When the plugin is deleted', 'kriti-ai-chatbot' ), true ); ?>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>[uninstall_present]" value="1" />
					<?php Ui::check( $name . '[delete_on_uninstall]', (bool) $s['delete_on_uninstall'], __( 'Also delete the index, conversations and settings', 'kriti-ai-chatbot' ), __( 'Off by default: deleting the plugin keeps its data, so a reinstall does not start from nothing.', 'kriti-ai-chatbot' ) ); ?>
				<?php Ui::field_close(); ?>
			</div>
		<?php Ui::panel_close(); ?>

		<div class="kai-savebar">
			<p><?php esc_html_e( 'Changes apply to the chat window straight away. Retrieval and meaning search changes apply to pages the next time they are indexed.', 'kriti-ai-chatbot' ); ?></p>
			<button type="submit" class="kai-btn kai-btn--primary"><span class="kai-icon" aria-hidden="true">save</span><?php esc_html_e( 'Save settings', 'kriti-ai-chatbot' ); ?></button>
		</div>
	</form>

	<?php Ui::panel_open( 'delete_forever', __( 'Delete conversations', 'kriti-ai-chatbot' ), __( 'Removes every stored question and answer, and the unanswered-question list built from them. This cannot be undone.', 'kriti-ai-chatbot' ), 'kai-panel--danger' ); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete every stored conversation?', 'kriti-ai-chatbot' ) ); ?>');">
			<input type="hidden" name="action" value="kai_chatbot_clear_conversations" />
			<?php wp_nonce_field( 'kai_chatbot_clear_conversations' ); ?>
			<button type="submit" class="kai-btn kai-btn--danger"><span class="kai-icon" aria-hidden="true">delete</span><?php esc_html_e( 'Delete all conversations', 'kriti-ai-chatbot' ); ?></button>
		</form>
	<?php Ui::panel_close(); ?>

	<?php Ui::footer(); ?>
</div>
