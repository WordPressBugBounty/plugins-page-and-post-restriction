jQuery(function($){
	var wp_inline_edit_function = inlineEditPost.edit;

	inlineEditPost.edit = function( post_id ) {

		wp_inline_edit_function.apply( this, arguments );

		var id = 0;
		if ( typeof( post_id ) == 'object' ) {
			id = parseInt( this.getId( post_id ) );
		}

		if ( id > 0 ) {

			var specific_post_edit_row = $( '#edit-' + id ),
			    specific_post_row = $( '#post-' + id ),
			    Allowed_Roles = $( '.column-Allowed_Roles', specific_post_row ).text(),
			    Private = $( '.column-Private', specific_post_row ).text(),
			    is_restricted = $( '.column-Private .papr-quick-edit-restricted', specific_post_row ).length > 0,
			    papr_fieldset = $( '.inline-edit-book', specific_post_edit_row );

			papr_fieldset.toggle( ! is_restricted );
			papr_fieldset.find( ':input' ).prop( 'disabled', is_restricted );
			if ( is_restricted ) {
				return;
			}

			$( ':input[name="Allowed_Roles"]', specific_post_edit_row ).val( Allowed_Roles );

			if(Private=='Yes'){
				required_login = true;	
				$( ':input[name="Private"]', specific_post_edit_row ).prop('checked', required_login );
			}
		}
	}
});