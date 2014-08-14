<?php

// File handle of Delicious.com export
$filename = '/Users/jmdodd/Dropbox/delicious.html';
$handle = fopen( $filename, 'r' );

// Include WordPress for access to the database
require( './wp-load.php' );

$contents = fread( $handle, filesize( $filename ) );
$contents = str_replace( PHP_EOL, '', $contents );

// Regex magic: see http://regex101.com for details
$regex = '/<dt><a href="(.*?)" add_date="(.*?)" private="(.*?)" tags="(.*?)">(.*?)<\/a><\/dt>(<dd(.*?)>(.*?)<\/dd>)?/im';
preg_match_all( $regex, $contents, $matches );
$raw = $matches[0];

// Map $matches to $post
// $matches[0][3] = <dt><a href="http://www.raywenderlich.com/" add_date="1406836564" private="0" tags="ios,tutorial,iphone">Ray Wenderlich</a></dt><dd>Tutorials for iPhone / iOS developers and gamers</dd>
// $matches[1][3] = http://www.raywenderlich.com/
// $matches[2][3] = 1406836564 (unixtime)
// $matches[3][3] = 0 (not private)
// $matches[4][3] = ios,tutorial,iphone
// $matches[5][3] = Ray Wenderlich
// $matches[8][3] = Tutorials for iPhone / iOS developers and gamers
$offset = get_option( 'gmt_offset' ) * 3600;
foreach ( $raw as $i => $r ) {
	// Convert unixtime to date
	$time = $matches[2][ $i ] + $offset;
	$date = date( 'Y-m-d H:i:s', $time );

	// Convert private to 'publish' or 'draft'
	if ( 0 == $matches[3][ $i ] ) {
		$status = 'publish';
	} else {
		$status = 'draft';
	}

	// Convert taxonomy tags
	$tags = array_map( 'trim', explode( ',', $matches[4][ $i ] ) );

	// Convert post content
	$content = "<a href='{$matches[1][ $i ]}'>{$matches[5][ $i ]}</a>";
	if ( ! empty( $matches[8][ $i ] ) ) {
		$content .= "\n\n{$matches[8][ $i ]}";
	}

	$post = array(
		'post_content'  => $content,
		'post_title'    => $matches[5][ $i ],
		'post_date'     => $date,
		'post_status'   => $status,
		'tags_input'    => $tags,
	);

	$id = wp_insert_post( $post, true );
	if ( ! is_a( $id, 'WP_Error' ) ) {
		update_post_meta( $id, '_delicious_link_url', $matches[1][ $i ] );
		update_post_meta( $id, '_delicious_link_description', $matches[8][ $i ] );

		echo "Added post id $id for '{$matches[5][ $i ]}': {$matches[1][ $i ]}\n";
	} else {
		print_r( $id );
	}
}

fclose( $handle );
