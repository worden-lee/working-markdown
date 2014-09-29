<?php

$WW_DIR = '../workingwiki';

$ww_sources = array(
	'WorkingWiki.defs.php',
	'misc.php',
	'WWInterface.php',
	'WWStorage.php',
	'ProjectDescription.php',
	'WorkingWikiProjectDescription.php',
	// TODO fill out this list thoughtfully
);

foreach ( $ww_sources as $php ) {
	require_once "$WW_DIR/$php";
}

$wwClickToAdd = false;
$wwLogFunction = function ( $string ) { print $string . "\n"; };

class WMDInterface extends WWInterface {

};

class WMDLocalProjectDescription extends WorkingWikiProjectDescription {
	public function __construct( $directory ) {
		$this->project_description_page = null;
		$this->project_files = array();
		$this->projectname = $directory;
		$this->uri = null;
		$this->options['use-default-makefiles'] = true; // ??
		$this->as_of_revision = null;
		foreach ( wwfFindFiles( $directory ) as $filename ) {
			$this->add_source_file( array(
				'filename' => $filename,
			) );
		}
		//$this->add_GNUmakefile(); // ??
		if ( ! is_array( ProjectDescription::$project_cache ) ) {
			ProjectDescription::$project_cache = array();
		}
		ProjectDescription::$project_cache[ $directory ] = $this;
	}
};


if ( php_sapi_name() !== 'cli' ) {
	error_log( "wmd.php called from web server" );
	header( "HTTP/1.0 500 Script execution error" );
?><html><head><title>Working Markdown Execution Error</title></head>
<body><h1>Error: Command-line script called from web server</h1>
<p>The wmd.php script can only be called as a command-line utility.</p>
<p>Usage: <tt>wmd.php &lt;input filename&gt; &lt;output filename&gt;</tt></p>
</body></html>
<?php
	exit -1;
}

@list( $script, $phase, $infilename, $outfilename ) = $argv;

if ( ! $infilename or ! $outfilename ) {
	file_put_contents('php://stderr', "Usage: wmd.php [--pre|--post] <input filename> <output filename>\n");
	exit -1;
}

$project = new WMDLocalProjectDescription( '.' );
WWInterface::$default_project_name = $project->project_name();

$tmpfilename = "$infilename.wmd-tmp";

if ( $phase == '--pre' ) {
	# preprocessing:
	
	# create and lock the token-data file

	# get the input text

	$intext = file_get_contents( $infilename );

	# find locations of all the tags in the text

	$tag_positions = WWStorage::find_project_files_on_page( $infilename, $intext ); 

	print json_encode( $tag_positions ) . "\n";

	# replace each tag by a token and output (where?)
	$outtext = '';
	$nextpos = 0;
	unset( $tag_positions['cache-filled'] );
	foreach ( $tag_positions as $projectname => $projecttags ) {
		if ( $projectname == '' ) {
			$projectname = WWInterface::$default_project_name;
		}
		foreach ( $projecttags as $filedata ) {
			print json_encode( $filedata ) . "\n";
			$outtext .= substr( $intext, $nextpos, $filedata['position'][0] - $nextpos );
			$tagargs = array(
				'filename' => $filedata['attributes']['filename'],
				'project' => $projectname,
				// TODO: support various args
			);
			if ( $filedata['source'] ) {
				$outtext .= WWInterface::source_file_hook(
					'',
					$tagargs,
					null
				);
			} else {
				$outtext .= WWInterface::project_file_hook(
					'',
					$tagargs,
					null
				);
			}
			$nextpos = $filedata['position'][1] + 1;
		}
	}
	$outtext .= substr( $intext, $nextpos );

	file_put_contents( $tmpfilename, $outtext );

	# record the location data
	file_put_contents( "$tmpfilename.token_data", json_encode( WWInterface::$uniq_tokens ) . "\n" );

} else {

	# postprocessing:

	# read in text with tokens
	$intext = file_get_contents( $tmpfilename );

	# read in token data
	WWInterface::$uniq_tokens = json_decode( file_get_contents( "$tmpfilename.token_data" ), true );

	$outtext = $intext;
	WWInterface::expand_tokens( $outtext, null, $infilename );

	# write text to output file
	file_put_contents( $outfilename, $outtext );
}
