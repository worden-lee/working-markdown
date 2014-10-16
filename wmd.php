<?php

$WW_DIR = '../workingwiki';

$ww_sources = array(
	'WorkingWiki.defs.php',
	'misc.php',
	'WWInterface.php',
	'WWStorage.php',
	'ProjectDescription.php',
	'WorkingWikiProjectDescription.php',
	'ProjectEngineConnection.php',
	'ProjectEngine/ProjectEngine.php',
	// TODO fill out this list thoughtfully
);

foreach ( $ww_sources as $php ) {
	require_once "$WW_DIR/$php";
}

$wwClickToAdd = false;
$wwLogFunction = function ( $string ) { print $string . "\n"; };
$wwUseHTTPForPE = false;
$wwPECanReadFilesFromWiki = $wwPEFilesAreAccessible = true;

$peAllowProcessingInPlace = true;

class WMDInterface extends WWInterface {
	public function reconcile_token_with_project( $token, $token_start, $token_length, $pagename ) {
		global $wwContext;
		$this->uniq_tokens[$token]['project'] =
			$wwContext->wwStorage->find_project_by_name(
				$this->uniq_tokens[$token]['args']['project']
			);
		$this->uniq_tokens[$token]['file_content'] = null;
	}

	public function page_is_history() {
		return false;
	}

	public function sse_key_if_any() {
		return null;
	}

	public function amend_PE_request( &$request ) {
		parent::amend_PE_request( $request );
		$request['log-to-stdout'] = true;
	}

	/* TODO: syntax highlighting */
	public function hasSyntaxHighlighter() {
		return false;
	}

	/* TODO: pulldown links or something? */
	public function altlinks_text( &$project, $display_filename, $args, $alts ) {
		return '';
	}
};

class WMDStorage extends WWStorage {
	public function ok_to_archive_files( $request ) {
		return false;
	}
};

class WMDLocalProjectDescription extends WorkingWikiProjectDescription {
	public function __construct( $directory ) {
		$this->project_description_page = null;
		$this->project_files = array();
		$this->projectname = $directory;
		$this->uri = preg_replace( '{/$}', '', "file://$directory" );
		$this->options['use-default-makefiles'] = true; // ??
		$this->as_of_revision = null;
		/*
		foreach ( wwfFindFiles( $directory ) as $filename ) {
			$this->add_source_file( array(
				'filename' => $filename,
			) );
		}
		*/
		//$this->add_GNUmakefile(); // ??
		if ( ! is_array( ProjectDescription::$project_cache ) ) {
			ProjectDescription::$project_cache = array();
		}
		ProjectDescription::$project_cache[ $directory ] = $this;
	}

	/* this class of project doesn't sync its source files into
	 * PE, because they're going to be used where they already are.
	 */
	public function all_source_file_contents() {
		return array();
	}

	public function fill_pe_request( &$request, $focal, $sync_sf ) {
		parent::fill_pe_request( $request, $focal, $sync_sf );
		$request['projects'][ $this->uri ]['process-in-place'] = true;
	}

	public function short_directory_name() {
		return $this->projectname;
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

$wwContext = new stdClass();
$wwContext->wwInterface = new WMDInterface;
$wwContext->wwStorage = new WMDStorage;

# note this needs to be an absolute path, not '.'
$project = new WMDLocalProjectDescription( realpath( '.' ) );

$wwContext->wwInterface->default_project_name = $project->project_name();

$tmpfilename = "$infilename.wmd-tmp";

if ( $phase == '--pre' ) {
	# preprocessing:
	
	# create and lock the token-data file

	# get the input text

	$intext = file_get_contents( $infilename );

	# find locations of all the tags in the text

	$tag_positions = $wwContext->wwStorage->find_project_files_on_page( $infilename, $intext ); 

	print json_encode( $tag_positions ) . "\n";

	# replace each tag by a token and output (where?)
	$outtext = '';
	$nextpos = 0;
	unset( $tag_positions['cache-filled'] );
	foreach ( $tag_positions as $projectname => $projecttags ) {
		if ( $projectname == '' ) {
			$projectname = $wwContext->wwInterface->default_project_name;
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
				$outtext .= $wwContext->wwInterface->source_file_hook(
					'',
					$tagargs,
					null
				);
			} else {
				$outtext .= $wwContext->wwInterface->project_file_hook(
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
	file_put_contents( "$tmpfilename.token_data", json_encode( $wwContext->wwInterface->uniq_tokens ) . "\n" );

} else {

	# postprocessing:

	# read in text with tokens
	$intext = file_get_contents( $tmpfilename );

	# read in token data
	$wwContext->wwInterface->uniq_tokens = json_decode( file_get_contents( "$tmpfilename.token_data" ), true );

	$outtext = $intext;
	$wwContext->wwInterface->set_page_being_parsed( $infilename ); // for MW_PAGENAME env var
	$wwContext->wwInterface->expand_tokens( $outtext, null, $infilename );

	# write text to output file
	file_put_contents( $outfilename, $outtext );
}
