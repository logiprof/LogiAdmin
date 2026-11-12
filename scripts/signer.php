<?php
/**
 * Signe un paquet LogiAdmin.
 * Usage : php scripts/signer.php <fichier.zip>
 * La clé privée est lue dans la variable LOGIADMIN_SIGNING_KEY ou dans le fichier
 * ~/.config/logiprof/logiadmin-signing.key (base64). Elle ne doit jamais être mise dans un dépôt.
 * Affiche deux lignes : l'empreinte SHA-256, puis la signature Ed25519 (base64).
 */
if ( $argc < 2 || ! is_file( $argv[1] ) ) {
	fwrite( STDERR, "Usage : php scripts/signer.php <fichier.zip>\n" );
	exit( 1 );
}
$key = getenv( 'LOGIADMIN_SIGNING_KEY' );
if ( ! $key ) {
	$path = getenv( 'HOME' ) . '/.config/logiprof/logiadmin-signing.key';
	$key  = is_file( $path ) ? trim( file_get_contents( $path ) ) : '';
}
$secret = base64_decode( trim( (string) $key ), true );
if ( ! $secret || strlen( $secret ) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ) {
	fwrite( STDERR, "Clé de signature introuvable : définissez LOGIADMIN_SIGNING_KEY ou créez ~/.config/logiprof/logiadmin-signing.key\n" );
	exit( 1 );
}
$data = file_get_contents( $argv[1] );
$sig  = sodium_crypto_sign_detached( $data, $secret );
// Contrôle : la signature doit être acceptée par la clé publique intégrée à LogiAdmin.
$src = file_get_contents( __DIR__ . '/../includes/class-logiadmin-updater.php' );
preg_match( "/const PUBLIC_KEY = '([^']+)'/", $src, $m );
if ( empty( $m[1] ) || ! sodium_crypto_sign_verify_detached( $sig, $data, base64_decode( $m[1] ) ) ) {
	fwrite( STDERR, "La clé privée ne correspond pas à la clé publique de LogiAdmin : signature refusée.\n" );
	exit( 1 );
}
echo hash( 'sha256', $data ), "\n", base64_encode( $sig ), "\n";
