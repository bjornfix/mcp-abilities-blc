<?php
/** Ability-category ownership contract. */

declare( strict_types=1 );

$source = file_get_contents( dirname( __DIR__ ) . '/mcp-abilities-blc.php' );
if ( false === $source ) {
	throw new RuntimeException( 'Unable to read the BLC ability module.' );
}
foreach ( array( "wp_abilities_api_categories_init', 'mcp_register_blc_ability_categories", "wp_has_ability_category( 'broken-link-checker' )", "wp_register_ability_category(\n\t\t'broken-link-checker'", "'category'            => 'broken-link-checker'" ) as $required ) {
	if ( false === strpos( $source, $required ) ) {
		throw new RuntimeException( 'BLC content-category ownership contract is incomplete.' );
	}
}
fwrite( STDOUT, "BLC ability-category contract passed.\n" );
