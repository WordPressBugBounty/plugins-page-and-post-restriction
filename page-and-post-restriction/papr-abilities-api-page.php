<?php
/**
 * Abilities API admin screen (WordPress Abilities + MCP).
 *
 * @package Page_And_Post_Restriction
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Abilities API settings page (similar flow to miniOrange SAML).
 *
 * @return void
 */
function papr_display_abilities_api_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'page-and-post-restriction' ) );
	}

	global $wp_version;

	$abilities_api_enabled = get_option( PAPR_Register_Abilities::OPTION_ENABLE_ABILITIES_API, 'false' );
	$api_ready             = PAPR_Register_Abilities::papr_abilities_api_available();
	$back_url              = admin_url( 'admin.php?page=page_restriction' );
	?>
	<div class="papr-bg-main papr-margin-left">
		<div class="wrap shadow-cstm p-3 me-0 mt-0 mo-saml-margin-left bg-white">
			<div class="row align-items-center">
				<div class="col-md-5 h3 ps-3 d-flex align-items-center">
					<img src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'includes/images/miniorange-logo.png' ); ?>" alt="" width="50" class="me-2">
					<span><?php esc_html_e( 'Page and Post Restriction', 'page-and-post-restriction' ); ?></span>
				</div>
				<div class="col-md-7 d-flex align-items-center justify-content-end flex-wrap gap-2">
					<a href="<?php echo esc_url( $back_url ); ?>" class="papr-btn-cstm rounded text-decoration-none d-inline-flex align-items-center px-3 py-2">
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-arrow-left me-1" viewBox="0 0 16 16" aria-hidden="true">
							<path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8z"/>
						</svg>
						<?php esc_html_e( 'Back to plugin configuration', 'page-and-post-restriction' ); ?>
					</a>
				</div>
			</div>
		</div>

		<div class="d-flex">
			<div class="col-md-9">
				<?php papr_message_success_fail(); ?>
				<div class="rounded bg-white papr-shadow p-4 mt-4 ms-4 mb-4">
					<form action="" method="post" id="papr_abilities_api_form">
						<?php wp_nonce_field( 'papr_abilities_api' ); ?>
						<input type="hidden" name="option" value="papr_abilities_api" />

						<div class="row align-items-start mb-3">
							<div class="col-md-8">
								<h4 class="papr-form-head d-flex align-items-center flex-wrap gap-2">
									<?php esc_html_e( 'Abilities API settings', 'page-and-post-restriction' ); ?>
									<a href="https://www.miniorange.com/blog/wordpress-api-abilities-and-mcp-ai-agents/" class="text-secondary" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e( 'Learn more', 'page-and-post-restriction' ); ?>">
										<svg width="18" height="18" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
											<path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
											<path d="M5.255 5.786a.237.237 0 0 0 .241.247h.825c.138 0 .248-.113.266-.25.09-.656.54-1.134 1.342-1.134.686 0 1.314.343 1.314 1.168 0 .635-.374.927-.965 1.371-.673.489-1.206 1.06-1.168 1.987l.003.217a.25.25 0 0 0 .25.246h.811a.25.25 0 0 0 .25-.25v-.105c0-.718.273-.927 1.01-1.486.609-.463 1.244-.977 1.244-2.056 0-1.511-1.276-2.241-2.673-2.241-1.267 0-2.655.59-2.75 2.286zm1.557 5.763c0 .533.425.927 1.01.927.609 0 1.028-.394 1.028-.927 0-.552-.42-.94-1.029-.94-.584 0-1.009.388-1.009.94z"/>
										</svg>
									</a>
								</h4>
							</div>
						</div>

						<div class="papr-bg-cstm p-3 rounded mt-3 mb-4">
							<?php if ( version_compare( $wp_version, '6.9', '>=' ) ) : ?>
								<h6 class="mb-2"><strong><?php esc_html_e( 'Prerequisites to enable the Abilities API (WordPress 6.9 or higher)', 'page-and-post-restriction' ); ?></strong></h6>
								<ul class="mb-0 text-secondary" style="list-style-type: disc; padding-left: 1.25rem;">
									<li>
										<?php esc_html_e( 'Install the', 'page-and-post-restriction' ); ?>
										<a href="https://github.com/WordPress/mcp-adapter/releases" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'MCP Adapter', 'page-and-post-restriction' ); ?></a>
										<?php esc_html_e( 'plugin and activate it.', 'page-and-post-restriction' ); ?>
									</li>
								</ul>
							<?php elseif ( version_compare( $wp_version, '6.8', '>=' ) ) : ?>
								<h6 class="mb-2"><strong><?php esc_html_e( 'Prerequisites to enable the Abilities API (WordPress 6.8 or higher)', 'page-and-post-restriction' ); ?></strong></h6>
								<ul class="mb-0 text-secondary" style="list-style-type: disc; padding-left: 1.25rem;">
									<li>
										<?php esc_html_e( 'Install the', 'page-and-post-restriction' ); ?>
										<a href="https://github.com/WordPress/mcp-adapter/releases" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'MCP Adapter', 'page-and-post-restriction' ); ?></a>
										<?php esc_html_e( 'plugin and activate it.', 'page-and-post-restriction' ); ?>
									</li>
									<li>
										<?php esc_html_e( 'Install the', 'page-and-post-restriction' ); ?>
										<a href="https://github.com/WordPress/abilities-api/releases/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Abilities API', 'page-and-post-restriction' ); ?></a>
										<?php esc_html_e( 'plugin and activate it.', 'page-and-post-restriction' ); ?>
									</li>
								</ul>
							<?php else : ?>
								<h6 class="mb-2"><strong><?php esc_html_e( 'Prerequisites to enable the Abilities API (WordPress 6.8 or higher)', 'page-and-post-restriction' ); ?></strong></h6>
								<ul class="mb-0 text-secondary" style="list-style-type: disc; padding-left: 1.25rem;">
									<li><?php esc_html_e( 'Upgrade WordPress to version 6.8 or higher.', 'page-and-post-restriction' ); ?></li>
									<li>
										<?php esc_html_e( 'Install the', 'page-and-post-restriction' ); ?>
										<a href="https://github.com/WordPress/abilities-api/releases/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Abilities API', 'page-and-post-restriction' ); ?></a>
										<?php esc_html_e( 'plugin and activate it.', 'page-and-post-restriction' ); ?>
									</li>
									<li>
										<?php esc_html_e( 'Install the', 'page-and-post-restriction' ); ?>
										<a href="https://github.com/WordPress/mcp-adapter/releases" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'MCP Adapter', 'page-and-post-restriction' ); ?></a>
										<?php esc_html_e( 'plugin and activate it.', 'page-and-post-restriction' ); ?>
									</li>
								</ul>
							<?php endif; ?>
						</div>

						<div class="row align-items-center mt-4">
							<div class="col-md-9">
								<h6 class="text-secondary mb-0">
									<strong><?php esc_html_e( 'Enable Abilities API for Page and Post Restriction', 'page-and-post-restriction' ); ?></strong>
								</h6>
								<?php if ( ! $api_ready && 'true' === $abilities_api_enabled ) : ?>
									<p class="small text-warning mb-0 mt-2"><?php esc_html_e( 'The option is enabled, but requirements are not met on this site yet. Install the Abilities API package (and MCP Adapter if required) or upgrade WordPress.', 'page-and-post-restriction' ); ?></p>
								<?php elseif ( ! $api_ready ) : ?>
									<p class="small text-secondary mb-0 mt-2"><?php esc_html_e( 'Install the prerequisites below before enabling.', 'page-and-post-restriction' ); ?></p>
								<?php endif; ?>
							</div>
							<div class="col-md-3">
								<label class="switch">
									<input type="checkbox" id="papr_enable_abilities_api" name="papr_enable_abilities_api" value="true"
										<?php checked( 'true', $abilities_api_enabled ); ?>
										onchange="document.getElementById('papr_abilities_api_form').submit();">
									<span class="slider round"></span>
								</label>
							</div>
						</div>

						<div class="papr-bg-cstm p-3 rounded mt-4">
							<p class="mb-0">
								<strong class="text-danger"><?php esc_html_e( 'Note:', 'page-and-post-restriction' ); ?></strong>
								<?php esc_html_e( 'Enabling this option can expose plugin abilities to MCP clients when your site is connected to an MCP-compatible client. Only enable if you understand the security implications.', 'page-and-post-restriction' ); ?>
							</p>
							<div class="row g-3 mt-2">
								<div class="col-md-4">
									<a href="https://plugins.miniorange.com/connect-wordpress-with-claude-mcp-guide" target="_blank" rel="noopener noreferrer" class="text-decoration-none text-dark d-block p-3 border rounded papr-abilities-guide-link" style="border-color: #ddd !important;">
										<strong class="d-block mb-1"><?php esc_html_e( 'Claude Desktop', 'page-and-post-restriction' ); ?></strong>
										<span class="text-secondary small d-block"><?php esc_html_e( 'Connect Claude Desktop to WordPress via MCP.', 'page-and-post-restriction' ); ?></span>
										<span class="text-primary small d-block mt-2"><?php esc_html_e( 'View setup guide →', 'page-and-post-restriction' ); ?></span>
									</a>
								</div>
								<div class="col-md-4">
									<a href="https://plugins.miniorange.com/connect-wordpress-with-cursor-mcp-guide" target="_blank" rel="noopener noreferrer" class="text-decoration-none text-dark d-block p-3 border rounded papr-abilities-guide-link" style="border-color: #ddd !important;">
										<strong class="d-block mb-1"><?php esc_html_e( 'Cursor', 'page-and-post-restriction' ); ?></strong>
										<span class="text-secondary small d-block"><?php esc_html_e( 'Connect Cursor to your WordPress site via MCP.', 'page-and-post-restriction' ); ?></span>
										<span class="text-primary small d-block mt-2"><?php esc_html_e( 'View setup guide →', 'page-and-post-restriction' ); ?></span>
									</a>
								</div>
								<div class="col-md-4">
									<a href="https://plugins.miniorange.com/wordpress-chatgpt-integration" target="_blank" rel="noopener noreferrer" class="text-decoration-none text-dark d-block p-3 border rounded papr-abilities-guide-link" style="border-color: #ddd !important;">
										<strong class="d-block mb-1"><?php esc_html_e( 'ChatGPT', 'page-and-post-restriction' ); ?></strong>
										<span class="text-secondary small d-block"><?php esc_html_e( 'Connect ChatGPT using miniOrange integration guides.', 'page-and-post-restriction' ); ?></span>
										<span class="text-primary small d-block mt-2"><?php esc_html_e( 'View setup guide →', 'page-and-post-restriction' ); ?></span>
									</a>
								</div>
							</div>
						</div>
					</form>
					<style>
						.papr-abilities-guide-link:hover { border-color: #2271b1 !important; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
					</style>
				</div>
			</div>
			<div class="col-md-3 papr_support_col ps-0 pe-0">
				<?php papr_support_page_restriction(); ?>
			</div>
		</div>
	</div>
	<?php
}
