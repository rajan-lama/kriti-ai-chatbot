<?php
/**
 * Dashboard view.
 *
 * @package KritiAIChatbot
 *
 * @var array $summary Numbers from Analytics::summary().
 */

defined( 'ABSPATH' ) || exit;

use KritiAIChatbot\Capabilities;
use KritiAIChatbot\Conversation_Store;
use KritiAIChatbot\Ui;

$jobs   = $summary['jobs'];
$rated  = $summary['helpful'] + $summary['not_helpful'];
$when   = static function ( $utc, $short = false ) {
	return $utc ? get_date_from_gmt( $utc, $short ? 'M j, ' . get_option( 'time_format' ) : get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '—';
};
$action = '';

if ( current_user_can( Capabilities::KNOWLEDGE ) ) {
	$action .= '<a class="kai-btn kai-btn--primary" href="' . esc_url( admin_url( 'admin.php?page=kriti-ai-chatbot-sources' ) ) . '"><span class="kai-icon" aria-hidden="true">database</span>' . esc_html__( 'Knowledge Sources', 'kriti-ai-chatbot' ) . '</a>';
}
?>
<div class="wrap kai-admin">
	<?php Ui::header( 'dashboard' ); ?>
	<h1 class="screen-reader-text"><?php esc_html_e( 'Kriti AI Chatbot', 'kriti-ai-chatbot' ); ?></h1>

	<?php Ui::title( __( 'Dashboard', 'kriti-ai-chatbot' ), __( 'How visitors use the chatbot, and how well your content answers them.', 'kriti-ai-chatbot' ), $action ); ?>

	<?php if ( ! Conversation_Store::enabled() ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'Conversations are not stored (see Settings → Privacy and limits), so there is nothing to show here.', 'kriti-ai-chatbot' ); ?></p></div>
	<?php endif; ?>

	<div class="kai-stats">
		<?php
		Ui::stat( __( 'Conversations', 'kriti-ai-chatbot' ), number_format_i18n( $summary['total_conversations'] ), 'forum' );
		Ui::stat(
			__( 'Questions', 'kriti-ai-chatbot' ),
			number_format_i18n( $summary['total_questions'] ),
			'chat',
			/* translators: 1: questions today, 2: questions this month. */
			sprintf( __( '%1$s today · %2$s this month', 'kriti-ai-chatbot' ), number_format_i18n( $summary['questions_today'] ), number_format_i18n( $summary['questions_this_month'] ) )
		);
		Ui::stat(
			__( 'Answer coverage', 'kriti-ai-chatbot' ),
			number_format_i18n( $summary['answered_percent'], 1 ) . '%',
			'verified',
			/* translators: %s: number of unanswered questions. */
			sprintf( __( '%s unanswered', 'kriti-ai-chatbot' ), number_format_i18n( $summary['unanswered'] ) ),
			true
		);
		Ui::stat(
			__( 'Rated helpful', 'kriti-ai-chatbot' ),
			$rated > 0 ? number_format_i18n( $summary['helpful_percent'], 1 ) . '%' : '—',
			'thumb_up',
			/* translators: 1: helpful ratings, 2: all ratings. */
			$rated > 0 ? sprintf( __( '%1$s of %2$s ratings', 'kriti-ai-chatbot' ), number_format_i18n( $summary['helpful'] ), number_format_i18n( $rated ) ) : __( 'No ratings yet', 'kriti-ai-chatbot' )
		);
		?>
	</div>

	<?php Ui::panel_open( 'database', __( 'Knowledge base', 'kriti-ai-chatbot' ), __( 'What the chatbot knows, and how indexing is going.', 'kriti-ai-chatbot' ) ); ?>
		<div class="kai-stats kai-stats--small">
			<?php
			Ui::mini( __( 'Indexed pages', 'kriti-ai-chatbot' ), number_format_i18n( $summary['sources'] ), 'article' );
			Ui::mini( __( 'Passages', 'kriti-ai-chatbot' ), number_format_i18n( $summary['chunks'] ), 'segment' );
			Ui::mini( __( 'With meaning search', 'kriti-ai-chatbot' ), number_format_i18n( $summary['embedded_chunks'] ), 'hub' );
			Ui::mini( __( 'Indexing waiting', 'kriti-ai-chatbot' ), number_format_i18n( $jobs['pending'] + $jobs['running'] ), 'hourglass_top' );
			Ui::mini( __( 'Indexing failed', 'kriti-ai-chatbot' ), number_format_i18n( $jobs['failed'] ), 'error' );
			Ui::mini( __( 'Last indexed', 'kriti-ai-chatbot' ), $when( $summary['last_sync'], true ), 'schedule' );
			Ui::mini( __( 'Average response', 'kriti-ai-chatbot' ), $summary['avg_response_ms'] > 0 ? number_format_i18n( $summary['avg_response_ms'] / 1000, 1 ) . ' s' : '—', 'speed' );
			Ui::mini( __( 'AI tokens (in / out)', 'kriti-ai-chatbot' ), number_format_i18n( $summary['token_input'] ) . ' / ' . number_format_i18n( $summary['token_output'] ), 'token' );
			?>
		</div>
	<?php Ui::panel_close(); ?>

	<div class="kai-columns">
		<?php Ui::panel_open( 'trending_up', __( 'Top questions', 'kriti-ai-chatbot' ), __( 'What visitors ask most.', 'kriti-ai-chatbot' ) ); ?>
			<?php if ( $summary['top_questions'] ) : ?>
				<table class="kai-table">
					<thead><tr><th><?php esc_html_e( 'Question', 'kriti-ai-chatbot' ); ?></th><th class="is-num"><?php esc_html_e( 'Asked', 'kriti-ai-chatbot' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $summary['top_questions'] as $row ) : ?>
						<tr><td><?php echo esc_html( $row['question'] ); ?></td><td class="is-num"><?php echo esc_html( number_format_i18n( $row['count'] ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="kai-empty"><?php esc_html_e( 'No questions yet.', 'kriti-ai-chatbot' ); ?></p>
			<?php endif; ?>
		<?php Ui::panel_close(); ?>

		<?php Ui::panel_open( 'link', __( 'Top sources', 'kriti-ai-chatbot' ), __( 'The pages answers are based on most often.', 'kriti-ai-chatbot' ) ); ?>
			<?php if ( $summary['top_sources'] ) : ?>
				<table class="kai-table">
					<thead><tr><th><?php esc_html_e( 'Page', 'kriti-ai-chatbot' ); ?></th><th class="is-num"><?php esc_html_e( 'Cited', 'kriti-ai-chatbot' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $summary['top_sources'] as $row ) : ?>
						<tr>
							<td>
								<?php if ( '' !== $row['url'] ) : ?>
									<a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row['title'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $row['title'] ); ?>
								<?php endif; ?>
							</td>
							<td class="is-num"><?php echo esc_html( number_format_i18n( $row['count'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="kai-empty"><?php esc_html_e( 'No answers with sources yet.', 'kriti-ai-chatbot' ); ?></p>
			<?php endif; ?>
		<?php Ui::panel_close(); ?>
	</div>

	<?php Ui::panel_open( 'help', __( 'Unanswered questions', 'kriti-ai-chatbot' ), __( 'Questions your content could not answer. Each one is a page or FAQ you may want to write.', 'kriti-ai-chatbot' ) ); ?>
		<?php if ( $summary['unanswered_list'] ) : ?>
			<table class="kai-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Question', 'kriti-ai-chatbot' ); ?></th>
						<th class="is-num"><?php esc_html_e( 'Times asked', 'kriti-ai-chatbot' ); ?></th>
						<th><?php esc_html_e( 'Last asked', 'kriti-ai-chatbot' ); ?></th>
						<th class="is-num"><?php esc_html_e( 'Evidence', 'kriti-ai-chatbot' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $summary['unanswered_list'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['question'] ); ?></td>
						<td class="is-num"><?php echo esc_html( number_format_i18n( $row['count'] ) ); ?></td>
						<td><?php echo esc_html( $when( $row['last_asked'] ) ); ?></td>
						<td class="is-num"><span class="kai-pill kai-pill--warn"><?php echo esc_html( number_format_i18n( $row['confidence'], 2 ) ); ?></span></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="kai-empty"><?php esc_html_e( 'Nothing unanswered. Good.', 'kriti-ai-chatbot' ); ?></p>
		<?php endif; ?>
	<?php Ui::panel_close(); ?>

	<?php Ui::footer(); ?>
</div>
