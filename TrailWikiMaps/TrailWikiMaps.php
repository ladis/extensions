<?php
/**
 * TrailWikiMaps extension - Create custom maps using SemanticMaps data over AJAX (for Elance job 28036402)
 *
 * See http://www.mediawiki.org/wiki/Extension:TreeAndMenu for installation and usage details
 * See http://www.organicdesign.co.nz/Extension_talk:TreeAndMenu.php for development notes and disucssion
 * 
 * @file
 * @ingroup Extensions
 * @author Aran Dunkley [http://www.organicdesign.co.nz/nad User:Nad]
 * @copyright © 2007 Aran Dunkley
 * @licence GNU General Public Licence 2.0 or later
 */

if( !defined( 'MEDIAWIKI' ) ) die( 'Not an entry point.' );

define( 'TRAILWIKIMAP_VERSION','0.0.1, 2012-01-15' );
define( 'TRAILWIKIMAP_NAME', 1 );
define( 'TRAILWIKIMAP_OFFSET', 2 );
define( 'TRAILWIKIMAP_LENGTH', 3 );
define( 'TRAILWIKIMAP_DEPTH', 4 );

$wgTrailWikiMagic              = "ajaxmap";
$wgExtensionFunctions[]        = 'wfSetupTrailWikiMaps';
$wgHooks['LanguageGetMagic'][] = 'wfTrailWikiMapsLanguageGetMagic';

$wgExtensionCredits['parserhook'][] = array(
	'path'        => __FILE__,
	'name'        => 'TrailWikiMaps',
	'author'      => '[http://www.organicdesign.co.nz/User:Nad Nad]',
	'url'         => 'https://www.elance.com/php/collab/main/collab.php?bidid=28036402',
	'description' => 'Create custom maps using SemanticMaps data over AJAX (for Elance job 28036402)',
	'version'     => TRAILWIKIMAP_VERSION
);

class TrailWikiMaps {

	var $opts = array(
		'"type":"TERRAIN"',
		'"zoom":8'
	);

	function __construct() {
		global $wgOut, $wgHooks, $wgParser, $wgResourceModules, $wgTrailWikiMagic;

		$wgHooks['UnknownAction'][] = $this;

		$wgParser->setFunctionHook( $wgTrailWikiMagic, array( $this,'expandAjaxMap' ) );
		$wgParser->setFunctionHook( 'ajaxmapinternal', array( $this,'expandAjaxMapInternal' ) );

		$wgResourceModules['ext.trailwikimaps'] = array(
			'scripts' => array( 'trailwikimaps.js' ),
			'styles' => array( 'trailwikimaps.css' ),
			'localBasePath' => dirname( __FILE__ ),
			'remoteExtPath' => basename( dirname( __FILE__ ) ),
		);
	}

	/**
	 * Return the trail data in JSON format when the ajaxmap action is requested
	 */
	function onUnknownAction( $action, $article ) {
		global $wgOut, $wgJsMimeType;

		// Update the information for the specified ISBN (or oldest book in the wiki if none supplied)
		// - if the book doesn't exist and the "create" query-string item is set, then create the book article
		if( $action == 'traillocations' ) {
			$wgOut->disable();
			header( 'Content-Type: application/json' );
			$comma = '';
			print "{\n";
			foreach( self::getTrailLocations() as $pos => $trails ) {
				print "$comma\"$pos\":[\"" . implode( '","', $trails ) . "\"]\n";
				$comma = ',';
			}
			print "}";
		}

		if( $action == 'trailinfo' ) {
			$wgOut->disable();
			global $wgTitle;
			$data = self::getTrailInfo( $wgTitle );

			// Render the info
			$info = "<b>Difficulty: </b><i>Unknown</i><br />";
			$info .= "<b>Distance: </b>" . $data['Distance'] . " Miles<br />";
			$info .= "<b>Trail Type: </b><i>Unknown</i><br />";
			$info .= "<b>Trail Uses: </b><i>Unknown</i><br />";

			// Get a thumbnail image if the image field is set
			$img = '';
			if( !empty( $data['Image Name'] ) ) {
				if( $img = wfLocalFile( $data['Image Name'] ) ) $img = $img->transform( array( 'width' => 140 ) )->toHtml();
			}

			// Return the data in a table
			print "<table><tr><td>$info</td><th>$img</th></tr></table>";
		}

		return true;
	}


	/**
	 * Expand #ajaxmap parser-functions
	 * - pretends to render a map from Extension:Maps to load the necessary resources
	 * - adds our internal parser-function to remove the dummy map and to define our map options in script
	 */
	public function expandAjaxMap() {
		global $wgOut, $wgJsMimeType;
		foreach( func_get_args() as $opt ) {
			if( !is_object( $opt ) && preg_match( "/^(\w+?)\s*=\s*(.*)$/s", $opt, $m ) ) {
				$v = is_numeric( $m[2] ) ? $m[2] : '"' . str_replace( '"', '', $m[2] ) . '"';
				$this->opts[] = "\"$m[1]\":$v";
			}
		}
		return array(
			'<div id="ajaxmap">{{#display_map:0,0}}{{#ajaxmapinternal:}}</div>',
			'found'   => true,
			'nowiki'  => false,
			'noparse' => false,
			'noargs'  => false,
			'isHTML'  => false
		);
	}

	public function expandAjaxMapInternal() {
		global $wgOut, $wgJsMimeType;
		$wgOut->addModules( 'ext.trailwikimaps' );
		$script = "window.ajaxmap_opt = {" . implode( ',', $this->opts ) . "};";
		$script .= "document.getElementById('ajaxmap').innerHTML = '';";
		return array(
			"<script type=\"$wgJsMimeType\">$script</script>",
			'found'   => true,
			'nowiki'  => false,
			'noparse' => true,
			'noargs'  => false,
			'isHTML'  => true
		);
	}

	/**
	 * Return array of args from the trail infobox for passed trail
	 */
	static function getTrailInfo( $title ) {
		$article = new Article( $title );
		preg_match( "|\{\{Infobox Trail(.+?)^\}\}|sm", $article->fetchContent(), $m );
		$template = preg_replace( "/(?<=\S)( +\| )/s", "\n$1", $m[1] ); // fix malformed template syntax
		preg_match_all( "|^\s*\|\s*(.+?)\s*= *(.*?) *(?=^\s*[\|\}])|sm", $template, $m );
		$data = array();
		foreach( $m[1] as $i => $k ) $data[trim( $k )] = trim( $m[2][$i] );
		return $data;
	}

	/**
	 * Build a list of trails at each location
	 */
	static function getTrailLocations() {
		$dbr   = &wfGetDB( DB_SLAVE );
		$tmpl  = $dbr->addQuotes( Title::newFromText( 'Infobox Trail' )->getDBkey() );
		$table = $dbr->tableName( 'templatelinks' );
		$res   = $dbr->select( $table, 'tl_from', "tl_namespace = 10 AND tl_title = $tmpl" );
		$list  = array();
		while( $row = $dbr->fetchRow( $res ) ) {
			$title = Title::newFromId( $row[0] );
			$trail = $title->getText();
			$data = self::getTrailInfo( $title );
			if( array_key_exists( 'Latitude', $data ) && array_key_exists( 'Longitude', $data ) ) {
				if( is_numeric( $data['Latitude'] ) && is_numeric( $data['Longitude'] ) ) {
					$pos = $data['Latitude'] . ',' . $data['Longitude'];
					if( !array_key_exists( $pos, $list ) ) $list[$pos] = array( $trail );
					else $list[$pos][] = $trail;
				}
			}
		}
		$dbr->freeResult( $res );
		return $list;
	}

}

function wfSetupTrailWikiMaps() {
	global $wgTrailWikiMaps;
	$wgTrailWikiMaps = new TrailWikiMaps();
}

function wfTrailWikiMapsLanguageGetMagic( &$magicWords, $langCode = 0 ) {
	global $wgTrailWikiMagic;
	$magicWords[$wgTrailWikiMagic] = array( $langCode, $wgTrailWikiMagic );
	$magicWords['ajaxmapinternal'] = array( $langCode, 'ajaxmapinternal' );
	return true;
}
