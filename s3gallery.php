<?php

/**
 * Copyright (c) 2011, Konstantinos Doskas.  All rights reserved.
 *
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 *
 * S3 gallery class
 * kdos 16-05-2011 for liberisdigital
 * Class reads folder structure and creates galleries based on the filestructure provided by style.com
 * Utilizes Amazon S3 PHP class
 * Returns valid xml file on success

 * @package s3Gallery
 *
 * @version 0.1.0-dev
 *
 */

class s3Gallery extends S3 {

/**
 * @static
 * @access private
 */
private static $awsAccessKey = '';
/**
 * @static
 * @access private
 */
private static $awsSecretKey = '';
/**
 * @static
 * @access private
 */
private static $bucket = 'gr.liberis.cn.gorunway';
/**
 * @static
 * @access private
 */
private static $interval = 86400;//60*60*24 cookie lifetime

/**
 * @var string -designers xml
 *
 */
public $designerXML;

/**
 * @var string - transformed structure
 */
public $fullXML;
/*
 * Access control policy
 *
 * public $acp = array("acl" => array("permission" =>  "READ_ACP"));
/*
 * ignore the following  folders/names
 * EXAMPLE: 3.1 Phillip Lim does also exist as 3_1 Phillip
 */

/**
 *
 * @var array - use these values to be excluded upon designerlist creation
 * *
 */
public $ignore = array('3');
/**
 * @var array
 *
 */
public $toBeStripped = array(' presentation', 'SPRING', 'COUTURE', '_REPLACEMENT_IMAGES');//known values which need to be stripped
/**
 * @var array 
 */
public $designerList = array();//currently not used
/**
 * @var string
 */
public $cleanNamePattern = "/(.)([A-Z])/";
/**
 * @var string
 */
public $replacement = "\\1 \\2";
/**
 * @var string
 */
public $seasonMatch = "(^[a-zA-Z]{1,2}$)";//match ss
/**
 * @var string
 */
public $seasonYearMatch = "(^[a-zA-Z0-9]{4}$)";//match ss11
/**
 * hint: perform caseinsensetive and exclusive check.
 * basically, these strings flag the existence of a certain gallery
 * Based on study
 * @var array
 */
public $imagetypes = array('Runway',
                            '_r',
                            'r_BIG',
                            'RUNWAYBIG',
                            'RUNWAYHIGHRES',
                            'RUNWAYBIGRES',
                            'r_h',
                            'frontrow',
                            'frontrowbig',
                            'frontrowhighres',
                            '_b',
                            'beauty',
                            'b_big',
                            'BEAUTYBIG',
                            'BEAUTYHIGHRES',
                            'BEAUTYBIGRES',
                            '_d',
                            'DETAILS',
                            'DETAILSBIG',
                            'DETAILSHIGHRES',
                            'DETAILSBIGRES',
                            'd_big',
                            'd_h',
                            'BD'
                            );
/**
 * List of known seasons
 * add new types that you may discover.
 * This is used to hold typical values which need to be stripped strip
 * @var array
 */
public $seasons = array('ss', 'fw', 'pf', 'spring');
/**
 * List of known styles
 * add new types that you may discover.
 * This is used to hold typical values which need to be stripped strip
 * @var array
 */
public $styles = array('menswear', 'couture', 'men', 'women');

/**
 * Constructor, wraps parent object
 * @return void
 *
 */
function __construct(){
    parent::__construct(self::$awsAccessKey, self::$awsSecretKey);
    /* manual testing s3 auth urls
    print '<img width="200" height="200" src="'
    .$this->buildImageUrl('2011_FW_FLORENCE_PITTI_MEN_GORUNWAY/GarethPugh_6_r/RUNWAY/00040m.jpg').
    '" / >';
    */
}
/**
* Basic method creates xml
*
* @return current object
*/
function gallery_builder(){
    $content = $this->getBucket(self::$bucket);

    //fake - load local file for development
    //deactivate outer foreach loop for local load/testing
    //$bucketFile = file('s3_gallery_output');
    
    foreach($content as $bucketFile){
         foreach($bucketFile as $k => $v){
              
             if($k == 'name'){//this key contains full paths
                
                 if((stristr($v, 'zip') !== FALSE) || (stristr($v, 'db') !== FALSE))//!!! ignore zip, db files
                     continue;
                 
                 if(strstr($v, '.') !== FALSE){
                    //collect list of currently available designers
                    $this->collectDesigners($v);
                    //TEST OUTPUT
                    print $v.'<br />';
                    //filter by conventions using the categorizer
                    $this->categorizer($v);
                 }
             }
         }
    }
    if(!empty($this->designerList))//create the designer xml file
        $this->createDesignerXML();
    if(!empty($this->fullXML))//create the full xml file
        $this->writeXMLFile();
    return $this;
}

/**
 * Attempts! (due to incosistent/non existant naming conventions)
 * to create the basic - yet essential full xml file
 * with per designer information based on year/season/location/imagetype/imagedimension
 * based s3 path information
 * Again, bare in mind that there is a lot of guesswork used here...
 *
 * Basic method creates xml
 *
 * @param string - current line of bucket array
 * @return void
 */
function categorizer($line){
	//Determine current folder name structure and parse out year information
	if(preg_match("/^[0-9]{4}_/", $line) )
	  $delimiter = '_';
	elseif(preg_match("/^[0-9]{4} /", $line) )
	  $delimiter = ' ';
	else
 	  $delimiter = '';
        $year = reset(explode($delimiter, $line));

	//grep the designer ( is expected to be at key=1 but no guarantee)
	$currentDesigner = explode('/', $line);
	//if the resulting string doesn't look like a designer name,
	//compare it with your static/known array values, if its in there
	//we have no designer name.Is that not the case, increment the index
	//to the next higher value and try again.
	//if you have no match, then its likely to be the missing designer name

        /************************ EXPERIMENTAL!!! ***************************/

        if(stristr($line, 'gorunway') == FALSE){
            for($z=0; $z < count($currentDesigner); $z++){
                if(!in_array($currentDesigner[$z], $this->imagetypes) 
                    && (!in_array($currentDesigner[$z], $this->seasons))
                    && (!in_array($currentDesigner[$z], $this->styles))
                    && (!preg_match("/.[a-zA-Z]{3}/$",$currentDesigner[$z]))//selected should not be a file//don't confuse with imagetype
                ){
                    $designer = $currentDesigner[$z];
                    break;
                }
                else{
                    $designer = 'n.a';
                    continue;
                }
            }
        }

        /********************************************************************/

        if(stristr($line, 'gorunway') !== FALSE){//gorunways seem to be based on conventions...kind of fuzzy though
            $designer = $currentDesigner[1];
        }

        //sanitize the string.
        //filtering is based on data set study
        
        $this->sanitize($designer);
        /*
        if(preg_match("/^[0-9]{4}(_| )/", $designer) !== FALSE)
            $designer = preg_replace("/^[0-9]{4}(_| )/", "", $designer);

        if(preg_match("(_[a-zA-Z0-9]{1,2}_[a-zA-Z0-9]{1,2})", $designer) )
            $designer = preg_replace("(_[a-zA-Z0-9]{1,2}_[a-zA-Z0-9]{1,2})", "", $designer);
        */

        //experimental
        $this->fullXML .= '<info>'.PHP_EOL;
	$this->fullXML .= '<designer>'.$designer.'</designer>'.PHP_EOL;
        if(is_numeric($year))
            $this->fullXML .= '<year>'.$year.'</year>'.PHP_EOL;
	for($x=0; $x < count($this->imagetypes); $x++){
	  if(stristr($line, $this->imagetypes[$x]) !== FALSE){
	     $this->fullXML .= '<imagetype>'.$this->imagetypes[$x].'</imagetype>'.PHP_EOL;
	  }
	}
	$this->fullXML .= '<fullpath>'.$line.'</fullpath>'.PHP_EOL;
	$this->fullXML .= '<authurl>'.$this->buildImageUrl($line).'</authurl>'.PHP_EOL;
	for($x=0; $x < count($this->seasons); $x++){
	  if(stristr($line, $this->seasons[$x]) !== FALSE){
	     $this->fullXML .= '<season>'.$this->seasons[$x].'</season>'.PHP_EOL;
	  }
	}
	for($x=0; $x < count($this->styles); $x++){
	  if(stristr($line, $this->styles[$x]) !== FALSE){
	     $this->fullXML .= '<styles>'.$this->styles[$x].'</styles>'.PHP_EOL;
	  }
	}
        $this->fullXML .= '</info>'.PHP_EOL;
}

/**
* Collect Designers
* @param string - current line of bucket array
* @return current object
*/
function collectDesigners($string){

    $designers = explode('/', $string);
    if(!in_array($designers[1], $cached)){
        $cached[] = $designers[1];
        //first element of this array usually conatins the name only
        $cleanName = reset(explode('_', $designers[1]));
        if(!in_array($cleanName, $this->designerList) && !in_array($cleanName, $this->ignore)){
            //transform StringsLikeThat into Strings Like That
            //perform string replacement
            $designername = preg_replace($this->cleanNamePattern, $this->replacement, $cleanName);
            $designername = str_replace("  "," ",$designername);
            if(!in_array(trim($designername), $this->designerList) )
                $this->designerList[] = trim($designername);
            asort($this->designerList);
        }
    }
    return $this;
}

/**
 * Sanitizes / the problematic designer name value
 * @param string &$string designername
 * @return boolean
 */
function sanitize(&$string){

    if(empty($string))
        return FALSE;
    if(preg_match("/^[0-9]{4}(_| )/", $string) !== FALSE)
        $string = preg_replace("/^[0-9]{4}(_| )/", "", $string);

    if(preg_match("/_[a-zA-Z]{1,3}$/", $string) !== FALSE)
        $string = preg_replace("/^[0-9]{4}(_| )/", "", $string);

    if(preg_match("(_[a-zA-Z0-9]{1,2}_[a-zA-Z0-9]{1,2})", $string) )
        $string = preg_replace("(_[a-zA-Z0-9]{1,2}_[a-zA-Z0-9]{1,2})", "", $string);
    //if(preg_match($this->cleanNamePattern, $string))
        //$string = preg_replace($this->cleanNamePattern, $this->replacement, $string);
    for($z=0; $z < count($this->toBeStripped); $z++){
        if(stristr($string, $this->toBeStripped[$z]))
            $string = str_replace($this->toBeStripped[$z], "", $string);
        else
            continue;
    }

    trim($string);
    
    return TRUE;
}
/**
 * Creates the designer XML file
 *
 * @return object
 */
function createDesignerXML(){
    
    $this->designerXML .= '<designerlist>';
    for($z=0; $z < count($this->designerList); $z++)
        $this->designerXML .= '<designer>'.$this->designerList[$z].'</designer>';
    $this->designerXML .= '</designerlist>';
    file_put_contents('designers.xml', $this->designerXML);

    return $this;
}


/**
 * creates a secure, temporary img url
 * @param string - bucket object URL
 * @returns string
 */
function buildImageUrl($currentObjUrl){
   return parent::getAuthenticatedURL(self::$bucket, $currentObjUrl, self::$interval);
}

/**
 * wraps setAccessControlPolicy.Use getAccessControlPolicy() to retrieve the permission array
 * for a specific bucket or object file
 * sets the policy for an image(persistant!)
 * @param string - bucket object URL
 * @returns string
 */
function setImagePolicy($currentObjUrl){
    
    return parent::setAccessControlPolicy(self::$bucket, $currentObjUrl, $this->acp);
}

/**
 * Creates the full XML file
 *
 * @return boolean
 */
function writeXMLFile(){
    return ((file_put_contents('full.xml', $this->fullXML) !== FALSE) ? TRUE : FALSE);
}

/*
 * Unused
 *
 */
function writeSQL(){

    
}


/*
 * DATA SCHEMA - PROPOSAL
 *
 * Tables:
 *
 * DESIGNERS
 * ---------
 * ID
 * NAME
 * CREATED
 * MODIFIED
 *
 * 
 * GALLERIES
 * ---------
 * GALLERY_ID
 * DESIGNER_ID
 * IMAGETYPE_URL
 * RAWPATH
 * AUTH_URL
 *
 * IMAGEDIMENSIONS
 * -----------
 * IMAGEDIMENSION_ID
 * IMAGEDIMENSION
 *
 *
 * IMAGETYPES
 * ----------
 * IMAGETYPE_ID
 * DESCRIPTION
 *
 * SEASONS
 * ----------
 * SEASON_ID
 * DESCRIPTION
 *
 *
 *
 *
 * END OF SCHEMA */
}
?>


