<?php
/*
 *      Minerva.php
 *      
 *      Copyright 2011 nemo <capt.n3m0@gmail.com>
 *      
 *      This program is free software; you can redistribute it and/or modify
 *      it under the terms of the GNU General Public License as published by
 *      the Free Software Foundation; either version 2 of the License, or
 *      (at your option) any later version.
 *      
 *      This program is distributed in the hope that it will be useful,
 *      but WITHOUT ANY WARRANTY; without even the implied warranty of
 *      MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *      GNU General Public License for more details.
 *      
 *      You should have received a copy of the GNU General Public License
 *      along with this program; if not, write to the Free Software
 *      Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
 *      MA 02110-1301, USA.
 */



class MinervaIndexer
{
	var $doc_root,$extensions,$pas,$isbn,$info;
	function __construct($root_dir){
		$this->doc_root = new RegexIterator(
			new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root_dir)),
			 '/^.+\.pdf$/i', RecursiveRegexIterator::GET_MATCH);
		$this->extensions = array('.pdf');
		$this->pas = new AmazonPAS();//amazon api
		R::setup("sqlite:minervaDB.sqlite");
		$this->isbn = new ISBN();
	}
	/**
	 * returns the levenshtein distance bw 
	 * two strings
	 */
	function levenshteinDistance($s1, $s2) 
	{ 
	  $sLeft = (strlen($s1) > strlen($s2)) ? $s1 : $s2; 
	  $sRight = (strlen($s1) > strlen($s2)) ? $s2 : $s1; 
	  $nLeftLength = strlen($sLeft); 
	  $nRightLength = strlen($sRight); 
	  if ($nLeftLength == 0) 
		return $nRightLength; 
	  else if ($nRightLength == 0) 
		return $nLeftLength; 
	  else if ($sLeft === $sRight) 
		return 0; 
	  else if (($nLeftLength < $nRightLength) && (strpos($sRight, $sLeft) !== FALSE)) 
		return $nRightLength - $nLeftLength; 
	  else if (($nRightLength < $nLeftLength) && (strpos($sLeft, $sRight) !== FALSE)) 
		return $nLeftLength - $nRightLength; 
	  else { 
		$nsDistance = range(1, $nRightLength + 1); 
		for ($nLeftPos = 1; $nLeftPos <= $nLeftLength; ++$nLeftPos) 
		{ 
		  $cLeft = $sLeft[$nLeftPos - 1]; 
		  $nDiagonal = $nLeftPos - 1; 
		  $nsDistance[0] = $nLeftPos; 
		  for ($nRightPos = 1; $nRightPos <= $nRightLength; ++$nRightPos) 
		  { 
			$cRight = $sRight[$nRightPos - 1]; 
			$nCost = ($cRight == $cLeft) ? 0 : 1; 
			$nNewDiagonal = $nsDistance[$nRightPos]; 
			$nsDistance[$nRightPos] = 
			  min($nsDistance[$nRightPos] + 1, 
				  $nsDistance[$nRightPos - 1] + 1, 
				  $nDiagonal + $nCost); 
			$nDiagonal = $nNewDiagonal; 
		  } 
		} 
		return $nsDistance[$nRightLength]; 
	  } 
	} 
	/**
	 * Starts the indexing
	 */
	function start(){
		foreach($this->doc_root as $file){
			$file = $file[0];//Since we've used regex matching, this is an array as well
			//First see if we've already indexed this file
			$book = R::findOne("book", "url=?",array($file));
			if($book) continue;
			
			$extension = substr($file,strrpos($file,"."));
			//find the extension
			if(in_array($extension,$this->extensions)){
				
				
				$filename = basename($file,$extension);
				$entered = false;
				
				$this->info = $this->pdfInfo($file);
				if(!isset($this->info['Pages'])) continue; //PDF is corrupt
				if($this->info['Pages']<20) continue;
				echo $file."\n";
				//Method 1 for searching document
				//search for an isbn inside the text
				$asin = $this->grepISBN($file);
				if($asin){
					$item = $this->amazon_lookup($asin);
					if($item){
						$this->saveInDB($item,$filename,$file);
						continue;
					}
				}
				
				//Method 2
				//if we did not find something,
				//let us ask amazon for help
				$item = $this->amazon_search($filename);
				$item = $item?$item:false;
				//Removed the sorting part as amazon gives better responses
				$this->saveInDB($item,$filename,$file);
				$this->deleteTextFile($file);
			}
		}
	}
	/**
	 * Cleanup function to delete
	 * the text file in /tmp
	 */
	function deleteTextFile($file){
		$hash = md5_file($file);
		@unlink("/tmp/minerva/$hash");
		@unlink("/tmp/minerva/$hash.1");
		@unlink("/tmp/minerva/$hash.2");
	}
	/**
	 * Saves the amazon output in our database
	 * Along with any additional fields required
	 */
	function saveInDB($item,$filename,$url){
		$book = R::dispense("book");
		if(gettype($item)=='object'):
			$book->asin = (string)$item->ASIN;
			$attrs = json_decode(json_encode($item->ItemAttributes));
			//This may either be stdClass object or SimpleXML object
			//depending on whether its cached or not
			//so we encode/decode it!
			foreach($attrs as $key=>$attr){
				if(is_array($attr)) $attr = implode(', ',$attr);
				echo "  * ".$key." => ".$attr."\n";
				$book->{$key} = (string) $attr;
			}
		endif;
		$book->filename = (string)$filename;
		$book->url = (string)$url;
		foreach($this->info as $key=>$attr){
			//echo "  * ".$key." => ".$attr."\n";
			$key = str_replace(" ","_",$key);
			if(!$book->{$key})	//if no such association exists
				$book->{$key} = (string) $attr;
		}
		$id = R::store($book);
		return $id;
	}
	/** 
	 * This function converts the pdf to a text file
	 * and searches for the occurence of the string in that file
	 * @depends pdftotext
	 * @return bool
	 */
	function searchInPdf($file,$string){
		$textFile = $this->textFile($file);
		echo "SEARCHING :" . $string."\n";
		$text = file_get_contents($textFile);
		if(strpos($text,$string)!==false){			
			$i=(strpos($text,$string));
			echo substr($text,$i-50,50)."\n";
			return true;
		}
		else
			return false;
	}
	/**
	 * Returns the text file name
	 * of the corresponding document
	 * Optimizes by not converting the whole pdf
	 * but rather the first and last 20 pages only
	 * and joining them together
	 */
	function textFile($file){
		$hash = md5_file($file);
		$textFile = "/tmp/minerva/$hash";
		if(!file_exists($textFile)){
			$startPage = $this->info['Pages']>20?$this->info['Pages']-20:0;
			system("pdftotext -q -f 0 -l 20 \"$file\" $textFile.1");
			system("pdftotext -q -f $startPage  \"$file\" $textFile.2");
			file_put_contents(
				$textFile,
				file_get_contents($textFile.".1").file_get_contents($textFile.".2")
			);
		}
		return $textFile;
	}
	/**
	 * Tries to find a valid isb number
	 * inside the text. It searches for ISBN13 values
	 * but converts them to isbn10 before returning
	 * as they do not have formatting isssues
	 */
	function grepISBN($file){
		$txt = $this->textFile($file);
		$arr = file($txt);
		$matches = preg_grep("/ISBN/i",$arr);
		$match = reset($matches);
		$isbn13 = $this->isbnInString($match);
		$isbn10 = $this->isbn->convert($isbn13,ISBN_VERSION_ISBN_13,ISBN_VERSION_ISBN_10);
		return $isbn10;
		
	}
	/**
	 * Cuts off the last 18 characters to 
	 * the correct ISBN13 length
	 */
	function isbnInString($str){
		//18 = 13(isbn) + 4(dashes) + 1(newline)
		return substr($str,-18);
	}
	/**
	 * Sorts the given items (iterable simplexml node)
	 * by levenshtein sort and returns them as an array
	 * consisting of distances vs ASIN pairs
	 */
	function levenshteinSort($arr,$base){
		$ref = array();
		foreach($arr->Item as $item){
			$ref[(int)$this->levenshteinDistance($item->ItemAttributes->Title,$base)] = $item;	//calculate distances
		}
		ksort($ref);
		return $ref;
	}
	/**
	 * This function looks up a product on amazon
	 * given its ASIN number. Since ASIN for all books
	 * is same as their ISBN (both 10/13), we search for 
	 * the isbn on facebook (10 digit, since parsing the 13 one
	 * was a bit difficult). Amazon returns a single Item
	 * as a result.
	 * The result is not cached (as of now)
	 */
	function amazon_lookup($isbn){
		if(file_exists("./cache/$isbn"))
			return json_decode(file_get_contents("./cache/$isbn"));
		else{
			$response = $this->pas->item_lookup($isbn);		
			$item = @$response->body->Items->Item;
			file_put_contents("./cache/$isbn",json_encode($item));
			return $item?$item:false;
		}
	}
	/**
	 * Searches amazon for a given string
	 * and returns the first item that amazon matched
	 * caches the results in a cache folder
	 */
	function amazon_search($string){
		if(file_exists("./cache/$string"))
			return json_decode(file_get_contents("./cache/$string"));
		else{
			$response = $this->pas->item_search($string);
			$item = $response->body->Items->Item[0];
			file_put_contents("./cache/$string",json_encode($item));
			return $item;
		}
	}
	/**
	 * @depends pdfinfo
	 * Uses pdfinfo command to get information about a pdf
	 * and returns it as an array
	 */
	function pdfInfo($pdf){
		$shell_output =shell_exec("pdfinfo \"$pdf\"");
		$pdfinfo = explode("\n",$shell_output);
		$pdf_parsed_info = array();
		array_walk($pdfinfo,function($item,$key,$pdf_parsed_info){
				$new_key = str_replace(array("# "," ",'-'),array("","_",'_'),substr($item,0,strpos($item,":")));				
				if($new_key):
					$new_text = substr($item,16);
					$pdf_parsed_info[$new_key] = $new_text;		
				endif;
		},&$pdf_parsed_info);
		return $pdf_parsed_info;
	}
}
