<?php

require_once 'page-restriction-menu-settings.php';
require_once 'page-restriction-page-access.php';

function papr_category_access() {
	$results_per_page = get_option( 'papr_category_per_page' );

	$results_per_page = $results_per_page != '' ? $results_per_page : 10;

	?>
	<div class="rounded bg-white papr-shadow p-4 mt-4 ms-4">

		<h4 class="papr-form-head">Give Access to Category based on Roles and Login Status</h4>

		<div class="papr-prem-info">
			<div class="papr-prem-icn papr-prem-cat-icn"><img src="https://img.icons8.com/color/48/000000/lock--v2.png" width="35px">
				<p class="papr-prem-info-text">Available in <b>Paid</b> versions of the plugin. <a href="<?php echo esc_url( Papr_Plugin_Links::PREMIUM_PLANS ); ?>" class="text-warning" target="_blank">Click here to upgrade</a></p>
			</div>
			<h5 class="papr-form-head papr-form-head-bar mt-2 mb-4">Category Restrictions
			<div class="papr-info-global ms-2">
					<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-info-circle-fill" viewBox="0 0 16 16">
						<path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16zm.93-9.412-1 4.705c-.07.34.029.533.304.533.194 0 .487-.07.686-.246l-.088.416c-.287.346-.92.598-1.465.598-.703 0-1.002-.422-.808-1.319l.738-3.468c.064-.293.006-.399-.287-.47l-.451-.081.082-.381 2.29-.287zM8 5.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2z"/>
					</svg>
					<p class="papr-info-text-global">
						Specify which post categories would be <b>accessible to only Logged In users</b> OR which <b>user roles should be able to access</b> the post categories in the table below.
					</p>
				</div>
			</h5>
			<div class="mb-4"> <b>Note:</b> All the posts of a restricted category would also be restricted.</div>
			<?php papr_dropdown( $results_per_page, 'category' ); ?>

			<div class="tablenav top mt-4">
				<input type="submit" class="btn papr-btn-cstm rounded" value="Save Configuration" disabled>
				<?php
				$categories                    = get_categories( array( 'hide_empty' => 0 ) );
				$total_category                = count( $categories );
				$number_of_pages_in_pagination = ceil( $total_category / $results_per_page );

				$current_page = papr_get_current_page( $number_of_pages_in_pagination );

				$offset     = ( $results_per_page * $current_page ) - $results_per_page;
				$categories = get_categories(
					array(
						'hide_empty' => 0,
						'type'       => 'post',
						'orderby'    => 'name',
						'order'      => 'ASC',
						'number'     => $results_per_page,
						'offset'     => $offset,
					)
				);

				$link = 'admin.php?page=page_restriction&tab=category_access&curr=';
				papr_pagination_button( $number_of_pages_in_pagination, $total_category, $current_page, $link, 'top' );
				?>
			</div>

			<table id="reports_table" class="wp-list-table widefat fixed striped table-view-list pages">
				<thead><?php papr_display_head_foot_of_table( 'Category' ); ?><thead>
					<tbody class="w-100">
						<?php
						foreach ( $categories as $category ) {
							papr_category_display_pages( $category );
						}
						?>
					</tbody>
				<tfoot>
					<?php papr_display_head_foot_of_table( 'Category' ); ?>
				</tfoot>
			</table>

			<div class="tablenav bottom mt-4">
				<input type="submit" class="btn papr-btn-cstm rounded" value="Save Configuration" form="blockedpagesform" disabled>
				<?php papr_pagination_button( $number_of_pages_in_pagination, $total_category, $current_page, $link, 'bottom' ); ?>
			</div>
		</div>
	</div>
	<script>
		var category_selector_up = document.getElementById("current-page-selector");
		var category_selector_down = document.getElementById("current-page-selector-1");
		var link = 'admin.php?page=page_restriction&tab=category_access&curr=';

		category_selector_up.addEventListener("keyup", function(event) {
			if (event.keyCode === 13) {
				category_selector_up_value = document.getElementById("current-page-selector").value;
				var page_link = link.concat(category_selector_up_value);
				window.open(page_link, "_self");
			}
		});

		category_selector_down.addEventListener("keyup", function(event) {
			if (event.keyCode === 13) {
				category_selector_down_value = document.getElementById("current-page-selector-1").value;
				var page_link = link.concat(category_selector_down_value);
				window.open(page_link, "_self");
			}
		});
	</script>
	<?php
}

function papr_category_display_pages( $category ) {
	?>
	<tr id="<?php echo esc_url( get_category_link( $category ) ); ?>">
		<td>
			<a href="<?php echo esc_url( get_category_link( $category ) ); ?>" target="_blank"><?php echo esc_html( $category->name ); ?> &nbsp;
				<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
					<path fill-rule="evenodd" d="M8.636 3.5a.5.5 0 0 0-.5-.5H1.5A1.5 1.5 0 0 0 0 4.5v10A1.5 1.5 0 0 0 1.5 16h10a1.5 1.5 0 0 0 1.5-1.5V7.864a.5.5 0 0 0-1 0V14.5a.5.5 0 0 1-.5.5h-10a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5h6.636a.5.5 0 0 0 .5-.5z"></path>
					<path fill-rule="evenodd" d="M16 .5a.5.5 0 0 0-.5-.5h-5a.5.5 0 0 0 0 1h3.793L6.146 9.146a.5.5 0 1 0 .708.708L15 1.707V5.5a.5.5 0 0 0 1 0v-5z"></path>
				</svg></a>
		</td>
			<td>
			<input class="w-75" type="text" name="mo_category_roles" id="mo_category_roles" placeholder="Enter (;) separated Roles" autocomplete="off" disabled>
			</td>

			<th scope="row" class="check-column">
			<label class="screen-reader-text" for="cb-select-3">
			</label>
			<input style="margin-left: 105px;" id="cb-select-3" name="mo_category_login" class="log_check" type="checkbox" disabled>

			<div class="locked-indicator">
			<span class="locked-indicator-icon" aria-hidden="true"></span>
			<span class="screen-reader-text"></span>
			</div>
			</th>
			</tr>
	<?php
}
?>