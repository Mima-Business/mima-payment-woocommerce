jQuery(document).ready(function ($) {
  // Add your custom JavaScript code here
    $('#woocommerce_mima_secret_key').after(
        '<button class="wc-mima-toggle-secret" style="height: 25px; margin-left: 2px; cursor: pointer">' +
        '<span class="dashicons dashicons-visibility"></span>' +
        '</button>'
    );

    $( '.wc-mima-toggle-secret' ).on( 'click', function( event ) {
        event.preventDefault();

        let $dashicon = $( this ).closest( 'button' ).find( '.dashicons' );
        let $input = $( this ).closest( 'tr' ).find( '.input-text' );
        let inputType = $input.attr( 'type' );

        if ( 'text' == inputType ) {
            $input.attr( 'type', 'password' );
            $dashicon.removeClass( 'dashicons-hidden' );
            $dashicon.addClass( 'dashicons-visibility' );
        } else {
            $input.attr( 'type', 'text' );
            $dashicon.removeClass( 'dashicons-visibility' );
            $dashicon.addClass( 'dashicons-hidden' );
        }
    } );
});
