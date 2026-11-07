<?php
/**
 * Markup shared by the admin screens: header bar, title, panels, fields, footer.
 *
 * The look follows the Kriti AI plugin so the plugins feel like one product; see assets/css/admin.css.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI helpers. Every method prints, and escapes what it prints.
 */
final class Ui {

	/**
	 * The top bar: brand, navigation, and a link to the site.
	 *
	 * @param string $current dashboard|sources|settings.
	 * @return void
	 */
	public static function header( $current ) {
		$items = array(
			'dashboard' => array( __( 'Dashboard', 'kriti-ai-chatbot' ), Capabilities::ANALYTICS, Admin::SLUG_DASHBOARD ),
			'sources'   => array( __( 'Knowledge Sources', 'kriti-ai-chatbot' ), Capabilities::KNOWLEDGE, Admin::SLUG_SOURCES ),
			'settings'  => array( __( 'Settings', 'kriti-ai-chatbot' ), Capabilities::MANAGE, Admin::SLUG_SETTINGS ),
		);
		?>
		<header class="kai-header">
			<a class="kai-brand" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::SLUG_DASHBOARD ) ); ?>">
				<?php if ( defined( 'KRITI_AI_URL' ) ) : ?>
					<img src="<?php echo esc_url( KRITI_AI_URL . 'images/kriti-ai-logo.png' ); ?>" alt="" />
				<?php else : ?>
					<span class="kai-icon" aria-hidden="true">forum</span>
				<?php endif; ?>
				<?php esc_html_e( 'Kriti AI Chatbot', 'kriti-ai-chatbot' ); ?>
			</a>
			<nav aria-label="<?php esc_attr_e( 'Chatbot sections', 'kriti-ai-chatbot' ); ?>">
				<ul class="kai-nav">
					<?php foreach ( $items as $key => $item ) : ?>
						<?php
						if ( ! current_user_can( $item[1] ) ) {
							continue;
						}
						?>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $item[2] ) ); ?>" class="<?php echo $key === $current ? 'is-active' : ''; ?>" <?php echo $key === $current ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $item[0] ); ?></a></li>
					<?php endforeach; ?>
					<?php if ( Text_Provider::available() && current_user_can( 'manage_options' ) ) : ?>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=kriti-ai-providers' ) ); ?>"><?php esc_html_e( 'Integrations Hub', 'kriti-ai-chatbot' ); ?></a></li>
					<?php endif; ?>
				</ul>
			</nav>
			<a class="kai-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener">
				<span class="kai-icon" aria-hidden="true">open_in_new</span>
				<?php esc_html_e( 'View site', 'kriti-ai-chatbot' ); ?>
			</a>
		</header>
		<?php
	}

	/**
	 * Page title, a line of explanation, and optional actions (already escaped HTML).
	 *
	 * @param string $title        Title.
	 * @param string $subtitle     One line under the title.
	 * @param string $actions_html Buttons, already escaped by the caller.
	 * @return void
	 */
	public static function title( $title, $subtitle = '', $actions_html = '' ) {
		?>
		<div class="kai-title-row">
			<div>
				<h1 class="kai-title"><?php echo esc_html( $title ); ?></h1>
				<?php if ( '' !== $subtitle ) : ?>
					<p class="kai-subtitle"><?php echo esc_html( $subtitle ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( '' !== $actions_html ) : ?>
				<div class="kai-actions"><?php echo $actions_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by the caller from escaped parts. ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * An icon in a tile.
	 *
	 * @param string $icon Material Symbols name.
	 * @return string
	 */
	public static function tile( $icon ) {
		return '<span class="kai-tile"><span class="kai-icon" aria-hidden="true">' . esc_html( $icon ) . '</span></span>';
	}

	/**
	 * A large metric card.
	 *
	 * @param string $label   Label.
	 * @param string $value   Value (plain text).
	 * @param string $icon    Icon name.
	 * @param string $note    Small line under the value.
	 * @param bool   $primary Highlight the card.
	 * @return void
	 */
	public static function stat( $label, $value, $icon, $note = '', $primary = false ) {
		?>
		<div class="kai-stat<?php echo $primary ? ' kai-stat--primary' : ''; ?>">
			<?php echo self::tile( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tile() escapes. ?>
			<div>
				<p class="kai-stat__label"><?php echo esc_html( $label ); ?></p>
				<p class="kai-stat__value"><?php echo esc_html( $value ); ?></p>
				<?php if ( '' !== $note ) : ?>
					<p class="kai-stat__note"><?php echo esc_html( $note ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * A compact metric card.
	 *
	 * @param string $label Label.
	 * @param string $value Value (plain text).
	 * @param string $icon  Icon name.
	 * @return void
	 */
	public static function mini( $label, $value, $icon ) {
		?>
		<div class="kai-stat">
			<div class="kai-stat__top">
				<?php echo self::tile( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tile() escapes. ?>
				<p class="kai-stat__label"><?php echo esc_html( $label ); ?></p>
			</div>
			<p class="kai-stat__value<?php echo strlen( $value ) > 12 ? ' is-text' : ''; ?>"><?php echo esc_html( $value ); ?></p>
		</div>
		<?php
	}

	/**
	 * Open a panel with a heading.
	 *
	 * @param string $icon        Icon name.
	 * @param string $title       Heading.
	 * @param string $description Text under the heading.
	 * @param string $modifier    Extra class, such as kai-panel--danger.
	 * @return void
	 */
	public static function panel_open( $icon, $title, $description = '', $modifier = '' ) {
		?>
		<section class="kai-panel <?php echo esc_attr( $modifier ); ?>">
			<div class="kai-panel__head">
				<?php echo self::tile( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tile() escapes. ?>
				<div>
					<h2 class="kai-panel__title"><?php echo esc_html( $title ); ?></h2>
					<?php if ( '' !== $description ) : ?>
						<p class="kai-panel__desc"><?php echo esc_html( $description ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		<?php
	}

	/**
	 * Close a panel.
	 *
	 * @return void
	 */
	public static function panel_close() {
		echo '</section>';
	}

	/**
	 * Open a form field: its label, then the caller prints the control.
	 *
	 * @param string $for   Id of the control the label belongs to, or an empty string for a group.
	 * @param string $label Label text.
	 * @param bool   $full  Span both columns.
	 * @return void
	 */
	public static function field_open( $for, $label, $full = false ) {
		echo '<div class="kai-field' . ( $full ? ' kai-field--full' : '' ) . '">';

		if ( '' !== $for ) {
			echo '<label class="kai-field__label" for="' . esc_attr( $for ) . '">' . esc_html( $label ) . '</label>';
		} else {
			echo '<span class="kai-field__label">' . esc_html( $label ) . '</span>';
		}
	}

	/**
	 * Close a field, with an optional hint under the control.
	 *
	 * @param string $hint Hint text.
	 * @return void
	 */
	public static function field_close( $hint = '' ) {
		if ( '' !== $hint ) {
			echo '<p class="kai-hint">' . esc_html( $hint ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * A boxed checkbox with a label and a line of explanation.
	 *
	 * @param string $name    Input name.
	 * @param bool   $checked Whether it is ticked.
	 * @param string $label   Label.
	 * @param string $help    Explanation.
	 * @return void
	 */
	public static function check( $name, $checked, $label, $help = '' ) {
		?>
		<label class="kai-check">
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked ); ?> />
			<span class="kai-check__text"><?php echo esc_html( $label ); ?>
				<?php if ( '' !== $help ) : ?>
					<small><?php echo esc_html( $help ); ?></small>
				<?php endif; ?>
			</span>
		</label>
		<?php
	}

	/**
	 * The bottom bar of the screen.
	 *
	 * @return void
	 */
	public static function footer() {
		?>
		<footer class="kai-footer">
			<nav aria-label="<?php esc_attr_e( 'Links', 'kriti-ai-chatbot' ); ?>">
				<a href="https://kritiai.net/privacy-policy"><?php esc_html_e( 'Privacy Policy', 'kriti-ai-chatbot' ); ?></a>
				<a href="https://kritiai.net/terms-of-service"><?php esc_html_e( 'Terms of Service', 'kriti-ai-chatbot' ); ?></a>
				<a href="https://kritiai.net/contact"><?php esc_html_e( 'Support', 'kriti-ai-chatbot' ); ?></a>
			</nav>
			<span>
				&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?>
				<a href="https://kritiai.net"><?php esc_html_e( 'Developed by Kriti AI.', 'kriti-ai-chatbot' ); ?></a>
			</span>
		</footer>
		<?php
	}
}
