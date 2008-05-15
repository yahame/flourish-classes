<?php
/**
 * Represents a file on the filesystem, also provides static file-related methods
 * 
 * @copyright  Copyright (c) 2007-2008 William Bond
 * @author     William Bond [wb] <will@flourishlib.com>
 * @license    http://flourishlib.com/license
 * 
 * @link  http://flourishlib.com/fFile
 * 
 * @uses  fCore
 * @uses  fDirectory
 * @uses  fEnvironmentException
 * @uses  fFilesystem
 * @uses  fProgrammerException
 * 
 * @version  1.0.0 
 * @changes  1.0.0    The initial implementation [wb, 2007-06-14]
 */
class fFile
{
	/**
	 * Creates a file on the filesystem and returns an object representing it.
	 * 
	 * This operation will be reverted by a filesystem transaction being rolled back.
	 * 
	 * @throws fValidationException
	 * 
	 * @param  string $file_path  The path to the new file
	 * @param  string $contents   The contents to write to the file, must be a non-NULL value to be written
	 * @return fFile
	 */
	static public function create($file_path, $contents)
	{
		if (empty($file_path)) {
			fCore::toss('fValidationException', 'No filename was specified');	
		}
		
		if (file_exists($file_path)) {
			fCore::toss('fValidationException', 'The file specified already exists');		
		}
		
		$directory = fFilesystem::getPathInfo($file_path, 'dirname');
		if (!is_writable($directory)) {
			fCore::toss('fEnvironmentException', 'The file path specified is inside of a directory that is not writable');		
		}

		file_put_contents($file_path, $contents);	
		
		$file = new fFile($file_path);
		
		fFilesystem::recordCreate($file);
		
		return $file;
	}
	
	
	/**
	 * The full path to the file
	 * 
	 * @var string 
	 */
	protected $file;
	
	/**
	 * An exception to be thrown if an action is performed on the file
	 * 
	 * @var Exception 
	 */
	protected $exception;
	
	
	/**
	 * Creates an object to represent a file on the filesystem
	 * 
	 * @throws fValidationException
	 * 
	 * @param  string $file_path  The path to the file
	 * @param  object $exception  An exception that was tossed during the object creation process
	 * @return fFile
	 */
	public function __construct($file_path, Exception $exception=NULL)
	{
		if (empty($file_path)) {
			// No file and no exception means we have nothing to work with
			if (!$exception) {
				fCore::toss('fValidationException', 'No filename was specified');	
			}
			
			// If we don't have a file, but have an exception, there may have been an issue creating the file
			$this->exception = $exception;
			return;
		}
		
		if (!file_exists($file_path)) {
			fCore::toss('fValidationException', 'The file specified does not exist');   
		}
		if (!is_readable($file_path)) {
			fCore::toss('fEnvironmentException', 'The file specified is not readable');   
		}
		
		// Store the file as an absolute path
		$file_path = realpath($file_path);
		
		// Hook into the global file and exception maps
		$this->file      =& fFilesystem::hookFilenameMap($file_path);   
		$this->exception =& fFilesystem::hookExceptionMap($file_path);
		
		// If we have an exception for this file, save it to the global exception map
		if ($exception) {
			fFilesystem::updateExceptionMap($file_path, $exception);
		}   
	}
	
	
	/**
	 * When used in a string context, represents the file as the filename
	 * 
	 * @return string  The filename of the file
	 */
	public function __toString()
	{
		return $this->getFilename();
	}
	
	
	/**
	 * Deletes the current file
	 * 
	 * This operation will NOT be performed until the filesystem transaction has been
	 * committed, if a transaction is in progress. Any non-Flourish code (PHP or system)
	 * will still see this file as existing until that point.
	 * 
	 * @return void
	 */
	public function delete() 
	{
		$this->tossIfException();
		
		if (!$this->getDirectory()->isWritable()) {
			fCore::toss('fProgrammerException', 'The file, ' . $this->file . ', can not be deleted because the directory containing it is not writable');
		} 
		
		// Allow filesystem transactions
		if (fFilesystem::isTransactionInProgress()) {
			return fFilesystem::recordDelete($this);	
		}
		
		@unlink($this->file);
		
		$exception = new fProgrammerException('The action requested can not be performed because the file has been deleted');
		fFilesystem::updateExceptionMap($this->file, $exception);
	}
	
	
	/**
	 * Creates a new file object with a copy of this file. If no directory is specified, the file
	 * is created with a new name in the current directory. If a new directory is specified, you must
	 * also indicate if you wish to overwrite an existing file with the same name in the new directory
	 * or create a unique name.
	 * 
	 * Will also put the file into the temp dir if it is currently in a temp dir.
	 * 
	 * This operation will be reverted by a filesystem transaction being rolled back.
	 * 
	 * @param  string|fDirectory $new_directory  The directory to duplicate the file into if different than the current directory
	 * @param  boolean           $overwrite      If a new directory is specified, this indicates if a file with the same name should be overwritten.
	 * @return fFile  The new fFile object
	 */
	public function duplicate($new_directory=NULL, $overwrite=NULL)
	{
		$this->tossIfException();
		
		if ($new_directory === NULL) {
			$new_directory = $this->getDirectory();	
		}
		
		if (!is_object($new_directory)) {
			$new_directory = fDirectory($new_directory);
		}
		
		if ($new_directory->getPath() == $this->getDirectory()->getPath()) {
			fCore::toss('fProgrammerException', 'The new directory specified, ' . $new_directory->getPath() . ', is the same as the current file\'s directory');
		}  
		
		if ($this->getDirectory()->isTemp()) {
			$new_directory = $new_directory->getTemp();
		}
		
		$new_filename = $new_directory->getPath() . $this->getFilename();
		
		$check_dir_permissions = FALSE;
		
		if (file_exists($new_filename)) {
			if (!is_writable($new_filename)) {
				fCore::toss('fProgrammerException', 'The new directory specified, ' . $new_directory . ', already contains a file with the name ' . $this->getFilename() . ', but is not writable'); 		
			}
			if (!$overwrite) {
				$new_filename = fFilesystem::createUniqueName($new_filename);	
				$check_dir_permissions = TRUE;
			}
		} else {
			$check_dir_permissions = TRUE; 
		} 
		
		if ($check_dir_permissions) {
			if (!$new_directory->isWritable()) {
				fCore::toss('fProgrammerException', 'The new directory specified, ' . $new_directory . ', is not writable');
			}	
		}		       
		
		@copy($this->getPath(), $new_filename);
		$file = new fFile($new_filename);
		
		// Allow filesystem transactions
		if (fFilesystem::isTransactionInProgress()) {
			fFilesystem::recordDuplicate($file);	
		}
		
		return $file;
	}
	
	
	/**
	 * Gets the directory the file is located in
	 * 
	 * @return fDirectory  The directory containing the file
	 */
	public function getDirectory()
	{
		$this->tossIfException();
		
		return new fDirectory(fFilesystem::getPathInfo($this->file, 'dirname'));    
	}
	
	
	/**
	 * Gets the filename (i.e. does not include the directory)
	 * 
	 * @return string  The filename of the file
	 */
	public function getFilename()
	{
		$this->tossIfException();
		
		// For some reason PHP calls the filename the basename, where filename is the filename minus the extension
		return fFilesystem::getPathInfo($this->file, 'basename');    
	}
	
	
	/**
	 * Gets the size of the file. May be incorrect for files over 2GB on certain operating systems.
	 * 
	 * @param  boolean $format          If the filesize should be formatted for human readability
	 * @param  integer $decimal_places  The number of decimal places to format to (if enabled)
	 * @return integer|string  If formatted a string with filesize in b/kb/mb/gb/tb, otherwise an integer
	 */
	public function getFilesize($format=FALSE, $decimal_places=1)
	{
		$this->tossIfException();
		
		// This technique can overcome signed integer limit
		$size = sprintf("%u", filesize($this->file));    
		
		if (!$format) {
			return $size;	
		}
		
		return fFilesystem::formatFilesize($size, $decimal_places);
	}
	
	
	/**
	 * Gets the file's current path (directory and filename)
	 * 
	 * @param  boolean $from_doc_root  If the path should be returned relative to the document root
	 * @return string  The path (directory and filename) for the file
	 */
	public function getPath($from_doc_root=FALSE)
	{
		$this->tossIfException();
		
		if ($from_doc_root) {
			return str_replace($_SERVER['DOCUMENT_ROOT'], '', $this->file);    
		}
		return $this->file;    
	}	
	
	
	/**
	 * Check to see if the current file is writable
	 * 
	 * @return boolean  If the file is writable
	 */
	public function isWritable()
	{
		$this->tossIfException();
		
		return is_writable($this->file);   
	} 
	
	
	/**
	 * Moves the file from the temp directory if it is not in the main directory already
	 * 
	 * @internal
	 * 
	 * @return void
	 */
	public function moveFromTemp()
	{
		$this->tossIfException();
		
		$directory = $this->getDirectory();
		if ($directory->isTemp()) {
			$new_file = $directory->getParent() . $this->getFilename();
			$this->rename($new_file);
		}    
	}
	
	
	/**
	 * Moves the file to the temp directory if it is not there already
	 * 
	 * @internal
	 * 
	 * @return void
	 */
	public function moveToTemp()
	{
		$this->tossIfException();
		
		$file_info = fFilesystem::getPathInfo($this->file);
		$directory = $this->getDirectory();
		if (!$directory->isTemp()) {
			$temp_dir = $directory->getTemp();
			$new_file = $temp_dir->getPath() . $this->getFilename();
			$this->rename($new_file);
		}    
	}
	
	
	/**
	 * Reads the data from the file. Reads all file data into memory, use with caution on large files!
	 * 
	 * This operation will read the data that has been written during the current transaction if one is in progress.
	 * 
	 * @param  mixed $data  The data to write to the file
	 * @return string  The contents of the file
	 */
	public function read() 
	{
		$this->tossIfException();
		
		return file_get_contents($this->file);
	}
	
	
	/**
	 * Renames the current file, if the filename already exists and the overwrite flag is set to false, a new filename will be created
	 * 
	 * This operation will be reverted if a filesystem transaction is in progress and is later rolled back.
	 * 
	 * @param  string  $new_filename  The new full path to the file
	 * @param  boolean $overwrite     If the new filename already exists, TRUE will cause the file to be overwritten, FALSE will cause the new filename to change
	 * @return void
	 */
	public function rename($new_filename, $overwrite) 
	{
		$this->tossIfException();
		
		if (!$this->getDirectory()->isWritable()) {
			fCore::toss('fProgrammerException', 'The file, ' . $this->file . ', can not be renamed because the directory containing it is not writable');	
		}
		
		$info = fFilesystem::getPathInfo($new_filename);
		
		if (!file_exists($info['dirname'])) {
			fCore::toss('fProgrammerException', 'The new filename specified, ' . $new_filename . ', is inside of a directory that does not exist');
		}
		
		// Make the filename absolute
		$new_filename = fDirectory::makeCanonical(realpath($info['dirname'])) . $info['basename'];
		
		if (file_exists($new_filename)) {
			if (!is_writable($new_filename)) {
				fCore::toss('fProgrammerException', 'The new filename specified, ' . $new_filename . ', already exists, but is not writable'); 		
			}
			if (!$overwrite) {
				$new_filename = fFilesystem::createUniqueName($new_filename);	
			}
		} else {
			$new_dir = new fDirectory($info['dirname']);
			if (!$new_dir->isWritable()) {
				fCore::toss('fProgrammerException', 'The new filename specified, ' . $new_filename . ', is inside of a directory that is not writable');
			} 
		}
		
		@rename($this->file, $new_filename);
		
		// Allow filesystem transactions
		if (fFilesystem::isTransactionInProgress()) {
			fFilesystem::recordRename($this->file, $new_filename);	
		}
		
		fFilesystem::updateFilenameMap($this->file, $new_filename);
	}
	
	
	/**
	 * Throws the file exception if exists
	 * 
	 * @return void
	 */
	protected function tossIfException()
	{
		if ($this->exception) {
			fCore::toss(get_class($this->exception), $this->exception->getMessage());
		}
	}
	
	
	/**
	 * Writes the provided data to the file. Requires all previous data to be stored in memory if inside a transaction, use with caution on large files!
	 * 
	 * If a filesystem transaction is in progress and is rolled back, the previous data will be restored.
	 * 
	 * @param  mixed $data  The data to write to the file
	 * @return void
	 */
	public function write($data) 
	{
		$this->tossIfException();
		
		if (!$this->isWritable()) {
			fCore::toss('fProgrammerException', 'This file can not be written to because it is not writable');
		} 
		
		// Allow filesystem transactions
		if (fFilesystem::isTransactionInProgress()) {
			fFilesystem::recordWrite($this);	
		}
		
		file_put_contents($this->file, $data);
	}      
}  



/**
 * Copyright (c) 2007-2008 William Bond <will@flourishlib.com>
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */