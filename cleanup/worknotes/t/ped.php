#!/usr/bin/php
<?php
/**
TODO:

	Save
*/
declare(ticks = 1);
mb_internal_encoding('UTF-8');

if(!posix_isatty(STDIN)) {
	Err::end('TTY required');
}

$ped = new Ped();

class Ped {
	protected $current = NULL;
	protected $docs = array();
	protected $mustRedraw = TRUE;
	protected $shouldStop = FALSE;
	protected $env = array();
	protected $keyboard = '';

	public function __construct() {
		system('stty -echo -icanon');
		stream_set_blocking(STDIN, 0);

		$args = new Args();
		foreach($args->files as $filename) {
			$this->load($filename);
		}
		if(sizeof($this->docs)==0)
			$this->docs[] = new Doc();

		$this->current = $this->docs[0];

		pcntl_signal(SIGCONT, array($this, '_sig'));	// Process has been stopped, and is now resuming
		pcntl_signal(SIGWINCH, array($this, '_sig'));	// Window size has changed
//		pcntl_signal(SIGTSTP, array($this, '_sig'));	// User pressed Ctrl+Z

		pcntl_signal(SIGHUP, array($this, '_sig'));
		pcntl_signal(SIGINT, array($this, '_sig'));
//		pcntl_signal(SIGKILL, array($this, '_sig'));	// Illegal to capture this
		pcntl_signal(SIGQUIT, array($this, '_sig'));
//		pcntl_signal(SIGSTOP, array($this, '_sig'));	// Illegal to capture this
		pcntl_signal(SIGTERM, array($this, '_sig'));


		//echo "Signal Handler installed";

		$this->run();
	}

	public function _sig($signal) {
		switch($signal) {
			case SIGWINCH:
			case SIGCONT:
				$this->mustRedraw = TRUE;
				break;
			default:
				echo "SIGNAL $signal\n";
				echo "SIGCONT ".SIGCONT." SIGHUP ".SIGHUP." SIGINT ".SIGINT." SIGKILL ".SIGKILL." SIGQUIT ".SIGQUIT." SIGSTOP ".SIGSTOP." SIGTERM ".SIGTERM." SIGWINCH ".SIGWINCH."\n";
				die();
				break;
		}
	}

	public function prompt($caption, $value, $callback) {
		echo "$caption: ";
		$res = readline($value);
	}

	public function doSave() {
		$this->prompt('File Name to Write:', $this->current->getFilename(), array($this, 'doSaveHandler'));
	}

	public function doSaveHandler($filename) {
		die("Got filename $filename");
	}

	public function doQuit() {
		echo "QUITTING\n";
		die();
	}

	public function run() {
		while(!$this->shouldStop) {
			$this->loop();
		}
		echo "STOPPING!";
	}

	public function loop() {
		if($this->mustRedraw) {
			$this->pullEnv();
			$this->redraw();
		}
		while(Keys::hasData()) {
			$this->receiveKey(Keys::getC());
		}
	}

	public function receiveKey($key) {
		$this->keyboard .= $key;
		$ord = ord($this->keyboard[0]);

		if($ord >= 192 && $ord <= 223) {
			// Wait for 2 bytes (UTF-8)
			if(strlen($this->keyboard)>=2) {
				$c = substr($this->keyboard, 0, 2);
				$this->keyboard = substr($this->keyboard, 2);
				$this->handleChar($c);
			}
			return;
		} else if($ord >= 224 && $ord <= 239) {
			// Wait for 3 bytes (UTF-8)
			if(strlen($this->keyboard)>=3) {
				$c = substr($this->keyboard, 0, 3);
				$this->keyboard = substr($this->keyboard, 3);
				$this->handleChar($c);
			}
			return;
		} else if($ord >= 240 && $ord <= 247) {
			// Wait for 3 bytes (UTF-8)
			if(strlen($this->keyboard)>=4) {
				$c = substr($this->keyboard, 0, 4);
				$this->keyboard = substr($this->keyboard, 4);
				$this->handleChar($c);
			}
			return;
		} else if($ord >= 248 && $ord <= 251) {
			// Wait for 4 bytes (UTF-8)
			if(strlen($this->keyboard)>=5) {
				$c = substr($this->keyboard, 0, 5);
				$this->keyboard = substr($this->keyboard, 5);
				$this->handleChar($c);
			}
			return;
		}

		switch($ord) {
			case 0: // Ctrl+<Space>
				$this->current->goNextToken();
				break;
			case 9: // <Tab>
				$this->current->doTab();
				break;
			case 10: // <Enter>
				$this->current->doEnter();
				break;
			case 11: // Ctrl+K
				$this->current->deleteLine($this->current->getLineNumber());
				break;
			case 15: // Ctrl+O
				$this->doSave();
				break;
			case 24: // Ctrl+X
				$this->doQuit();
				break;
			case 27: // ANSI Escape Sequence possibly
				if(isset($this->keyboard[1])) {
					if($this->keyboard[1]=='[') {
						// Expect escape sequence
						$tmp = '';
						for($i = 2; $i < 20; $i++) {
							if(!isset($this->keyboard[$i])) {
								// We don't have enough to parse the possible CSI
								return;
							}
							$c = $this->keyboard[$i];
							$tmp .= $c;
							if(trim($c, '~abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ')==='') {
								// We have a character
								$this->keyboard = substr($this->keyboard, strlen($tmp)+2);
								$this->handleCsi($tmp);
								// Do not continue
								return;
							}
						}
					} else {
						// No escape sequence will come, pass on the ESC char
						$this->handleChar($this->keyboard[0]);
						break;
					}
				} else {
					// We don't have enough to parse the possible CSI
					$this->drawHeader();
					$this->drawFooter();
					return;
				}

				break;
			case 127: // <backspace>
				$this->current->doBackspace();
				break;
			default :
				$this->handleChar($this->keyboard[0]);
				break;
		}
		$this->keyboard = substr($this->keyboard, 1);
		$this->drawHeader();
		$this->drawFooter();
	}

	public function handleChar($c) {
/*
		switch(ord($c)) {
			default:
				echo "CHAR: $c (".ord($c).")\n";
//usleep(250000);$this->redraw();
				break;
		}
*/
		$this->current->doChar($c);
		$this->drawHeader();
		$this->drawFooter();
	}

	public function handleCsi($seq) {
		switch($seq) {
			case 'A': // UP
				$this->current->goUp();
				break;
			case 'B': // DOWN
				$this->current->goDown();
				break;
			case 'C': // RIGHT
				$this->current->goRight();
				break;
			case 'D': // LEFT
				$this->current->goLeft();
				break;
			case '1~': // <home>
				$this->current->goStartOfLine();
				break;
			case '2~': // <insert>
				$this->current->redraw();
				break;
			case '3~': // <delete>
				$this->current->doDelete();
				break;
			case '4~': // <end>
				$this->current->goEndOfLine();
				break;
			case '5~': // Page Up
				$this->current->goUp(15);
				break;
			case '6~': // Page Down
				$this->current->goDown(15);
				break;
			default:
				echo "SEQ: '$seq' (".strlen($seq).")\n";
die();
				break;
		}
		$this->drawHeader();
		$this->drawFooter();
	}

	public function redraw() {
		Csi::backgroundColor(0);
		$this->drawHeader();
		$this->drawFooter();
		$this->drawDoc();
		$this->mustRedraw = FALSE;
	}

	public function drawDoc() {
		$this->current->draw(2, 1, $this->env['terminal']['height']-4, $this->env['terminal']['width']);
	}

	public function drawHeader() {
		Csi::store();
		Csi::cursorTo(1,1);
		Csi::backgroundColor(7);
		Csi::color(0);
		$l = new LineWriter();
		$l->addWhite(2);
		$l->addLeft('PED 0.1', 20);
		$l->addCenter('frodes teksteditor');
		if($this->current->isDirty()) {
			$l->addRight('Modified');
		} else {
			$l->addRight("");
		}
		$l->addWhite(2);
		$l->draw($this->env['terminal']['width']);
		Csi::restore();
	}

	public function drawFooter() {
		Csi::store();
		Csi::cursorTo($this->env['terminal']['height']-2, 1);
		Csi::backgroundColor(7);
		Csi::color(0);
		$l = new LineWriter();
		$l->addLeft('^X Exit');
		$ord = $this->current->getCurrentChar();
		$ordText = 'char ';
		$ordDec = array();
		for($i = 0; $i < strlen($ord); $i++) {
			$ordDec[] = '0x'.strtoupper(dechex(ord($ord[$i])))." (".ord($ord[$i]).")";
		}
		$ordText .= implode(",", $ordDec)." ";;

		$l->addRight($ordText."line ".$this->current->getLineNumber()."/".$this->current->getLineCount()." col ".$this->current->getCol()."/".mb_strlen($this->current->getCurrentLine(TRUE)));
		$l->addWhite(2);
		$l->draw($this->env['terminal']['width']);
		Csi::restore();
	}

	public function pullEnv() {
		$res = array();
		$res['terminal'] = array();
		list($terminalHeight, $terminalWidth) = explode(" ", trim(shell_exec('stty size')));
		$res['terminal']['width'] = intval($terminalWidth);
		$res['terminal']['height'] = intval($terminalHeight);
		$this->env = $res;
	}

	public function load($filename) {
		$this->debug("Loading $filename");
		$this->docs[] = new Doc($filename);
	}

	public function debug($msg) {
//		echo "Dbg: $msg\n";
	}

	public function __destruct() {
		system('stty echo icanon');
//		system('reset');
	}
}


class Keys {
	protected static $buffer = '';

	protected static function fillBuffer() {
		$c = fgetc(STDIN);
		self::$buffer .= $c;
	}

	public static function hasData() {
		self::fillBuffer();
		return strlen(self::$buffer)>0;
	}

	public static function getC() {
		if(self::hasData()) {
			$c = self::$buffer[0];
			self::$buffer = substr(self::$buffer, 1);
			return $c;
		}
		return NULL;
	}
}


class Args {
	public $files = array();

	public function __construct() {
		global $argv;
		for($i = 1; $i < sizeof($argv); $i++) {
			$this->files[] = $argv[$i];
		}
	}
}


class Doc {
	protected $dirty = FALSE;
	protected $buffer = array();
	protected $bufferLength = 0;
	protected $filename;
	protected $line=1, $col=1, $realCol=1;
	protected $scrollTop = 0;
	protected $tabSize = 4;

	public function __construct($filename=NULL) {
		$this->filename = $filename;
		$this->load();
		$this->line = 1;
	}

	public function load() {
		if(file_exists($this->filename)) {
			$this->buffer = array();
			$this->bufferLength = 0;
			$fp = fopen($this->filename, 'rb');
			while(($line = fgets($fp)) !== FALSE) {
				if(strpos($line, "\r")) {
					$line = str_replace("\r", "", $line);
					$this->dirty = TRUE;
				}
				$l = strlen($line);
				if($line[$l-1] == "\n") {
					$line = substr($line, 0, $l-1);
				}
				$line = $this->fixTabs($line);
//echo "\n\n";
				$this->buffer[$this->bufferLength++] = $line;
			}
			if(sizeof($this->buffer)===0)
				$this->buffer[$this->bufferLength++] = '';
		} else {
			$this->bufferLength = 1;
			return $this->buffer = array('');
		}
	}

	public function fixTabs($text) {
		while(($p = strpos($text, "\t")) !== FALSE) {
			$nextPos = floor($p / $this->tabSize) * $this->tabSize + $this->tabSize;
			$spaces = $nextPos - $p;
			$text = substr($text, 0, $p).str_repeat(" ", $spaces).substr($text, $p+1);
		}
		return $text;
	}

	public function isDirty() {
		return $this->dirty;
	}

	public function doTab() {
		$line = $this->getCurrentLine();
		$p = $this->col - 1;
		$nextPos = floor($p / $this->tabSize) * $this->tabSize + $this->tabSize;
		$spaces = $nextPos - $p;
		$line = substr($line, 0, $p).str_repeat(" ", $spaces).substr($line, $p);
		$this->realCol = $this->col += $spaces;
		$this->setCurrentLine($line);
	}

	public function doEnter() {
		$this->dirty = TRUE;
		// Split the current line at the current col
		$line = $this->getCurrentLine(TRUE);
		if($this->bufferLength > $this->line)
			$nextLine = $this->getLine($this->line + 1, TRUE);
		else
			$nextLine = FALSE;

		$left = mb_substr($line, 0, $this->col-1);
		$right = mb_substr($line, $this->col-1);

		$this->setCurrentLine($left);
		$this->line++;
		$this->realCol = $this->col = 1;
		$this->setCurrentLine($right);
		if($nextLine !== FALSE)
			$this->insertLine($this->line + 1, $nextLine);
		$this->placeCursor();
	}

	public function doDelete() {
		if($this->col == mb_strlen($this->getCurrentLine())+1) {
			// Take the next line and append it here
			$nextLine = $this->getLine($this->line+1);
			$this->deleteLine($this->line+1);
			$this->setLine($this->line, $this->getCurrentLine().$nextLine);
		} else {
			$line = $this->getCurrentLine();
			// Accomodate for tabs
			if((($this->col - 1) % $this->tabSize === 0) && trim(mb_substr($line, 0, $this->col + $this->tabSize - 1))==='') {
				$line = substr($line, $this->tabSize);
			} else {
				// Delete the character at the current position
				$left = mb_substr($line, 0, $this->col-1);
				$right = mb_substr($line, $this->col);
				$line = $left.$right;
			}
			$this->setLine($this->line, $line);
		}
	}

	public function doBackspace() {
		if($this->col == 1 && $this->line > 1) {
			$this->dirty = TRUE;

			$currentLine = $this->getCurrentLine(TRUE);
			$previousLine = $this->getLine($this->line - 1);

			// Append current line to previous line.
			$this->line--;
			$this->realCol = $this->col = mb_strlen($previousLine) + 1;
			$this->setCurrentLine($previousLine.$currentLine);
			if($this->bufferLength > $this->line)
				$this->deleteLine($this->line + 1);
/*

			// Append current line to previous line. Remove previous line's line ending
			$line = $this->getCurrentLine(TRUE);
			if($this->line <= $this->bufferLength)
				$this->deleteLine($this->line);
			$this->line--;

			$newLine = $this->getCurrentLine();
			$this->realCol = $this->col = mb_strlen($newLine) + 1;
			$newLine .= $line;
			$this->setLine($this->line, $newLine);
			$this->redrawLineNumber($this->line+1);
			$this->placeCursor();
*/
		} else if($this->col > 1) {
			$line = $this->getCurrentLine();

			// Detect and accomodate for tabs at the beginning of the line
			if((($this->col - 1) % $this->tabSize === 0) && trim(mb_substr($line, 0, $this->col - 1))=='') {
				$line = substr($line, $this->tabSize);
				$this->col -= $this->tabSize;
			} else {
				$line = mb_substr($line, 0, $this->col - 2).mb_substr($line, $this->col - 1);
				$this->col--;
			}
			$this->setLine($this->line, $line);
			$this->realCol = $this->col;
			$this->placeCursor();
		}
	}

	public function goNextToken() {
		$this->goRight();
		$currentType = Token::getType($this->getCurrentChar());
		while(TRUE) {
			$this->goRight();
			$thisType = Token::getType($this->getCurrentChar());
			if($thisType == Token::WHITESPACE)
				$currentType = Token::WHITESPACE;
			if($thisType != Token::WHITESPACE && $currentType != $thisType) {
				break;
			}
		}
	}

	public function goLeft() {
		$this->col--;
		if($this->col < 1 && $this->line <= 1) {
			$this->col = 1;
			$this->line = 1;
		} else if($this->col < 1) {
			$this->line--;
			$this->col = mb_strlen($this->getCurrentLine()) + 1;
		}
		$this->realCol = $this->col;
		$this->placeCursor();
	}

	public function goRight() {
		$this->col++;
		if($this->col > mb_strlen($this->getCurrentLine(TRUE)) + 1) {
			$this->col = 1;
			if($this->line < $this->getLineCount() + 1)
				$this->line++;
			else
				$this->line = 1;
		}
		$this->realCol = $this->col;
		$this->placeCursor();
	}

	public function goEndOfLine() {
		$this->realCol = $this->col = mb_strlen($this->getCurrentLine()) + 1;
		$this->placeCursor();
	}

	public function goStartOfLine() {
		$line = $this->getCurrentLine();
		$trimmedLine = ltrim($line);

		$textOffset = strlen($line) - strlen($trimmedLine) + 1;

		if($this->col === $textOffset) {
			$this->realCol = $this->col = 1;
		} else {
			$this->realCol = $this->col = $textOffset;
		}
		$this->placeCursor();
	}

	public function goUp($distance=1) {
		$this->line -= $distance;
		if($this->line < 1)
			$this->line = 1;
		if($this->realCol > mb_strlen($this->getCurrentLine()) + 1)
			$this->col = mb_strlen($this->getCurrentLine()) + 1;
		else
			$this->col = $this->realCol;
		$this->placeCursor();
	}

	public function goDown($distance=1) {
		$this->line += $distance;
		if($this->line > $this->getLineCount()) {
			$this->line = $this->getLineCount() + 1;
			$this->col = 1;
		} else {
			if($this->realCol > mb_strlen($this->getCurrentLine()) + 1)
				$this->col = mb_strlen($this->getCurrentLine()) + 1;
			else
				$this->col = $this->realCol;
		}

		$this->placeCursor();
	}

	public function doChar($char) {
		$this->insertChar($this->col, $this->line, $char);
		$this->col++;
		$this->realCol = $this->col;
		$this->placeCursor();
	}

	public function insertChar($col, $line, $char) {
		$text = $this->getLine($line, TRUE);
		$left = mb_substr($text, 0, $col-1);
		$right = mb_substr($text, $col-1);
		$this->setLine($line, $left.$char.$right);
	}

	public function insertLine($line, $text='') {
		if($line > $this->bufferLength + 1) {
			throw new Exception("Trying to insert line beyond the end of the file");
		} else if($line < 1) {
			throw new Exception("Trying to insert a line before the beginning (which is 1)");
		}
		$this->bufferLength++;
		$this->dirty = TRUE;
		array_splice($this->buffer, $line-1, 0, array($text));
		$realLine = $line + $this->drawLine - $this->scrollTop - 1;
		if($realLine >= $this->drawLine && $realLine <= $this->drawLine + $this->drawLines) {
			Csi::store();
			Csi::cursorTo($this->drawLine + $this->drawLines, 1);
			Csi::deleteLine(1);
			Csi::cursorTo($realLine, 1);
			Csi::insertLine(1);
			Csi::restore();
			$this->redrawLine($line);
			$this->redrawLineNumbersFrom($line);
		}
	}

	public function deleteLine($line) {
		if($line > $this->bufferLength) {
			throw new Exception("Trying to delete line beyond the end of the file");
		} else if($line < 1) {
			throw new Exception("Trying to delete a line before the beginning (which is 1)");
		}
		$this->bufferLength--;
		$this->dirty = TRUE;
		array_splice($this->buffer, $line - 1, 1);
		$realLine = $this->line + $this->drawLine - $this->scrollTop;
		if($realLine >= $this->drawLine && $realLine <= $this->drawLine + $this->drawLines) {
			Csi::store();
			// Delete the requested line
			Csi::cursorTo($realLine, 1);
			Csi::deleteLine(1);
			// Insert a blank line to move the footer down
			Csi::cursorTo($this->drawLine + $this->drawLines - 1, $this->drawCol);
			Csi::insertLine(1);
			// Draw the line we just made room for at the bottom
			$this->redrawLine($this->scrollTop + $this->drawLines);
			Csi::restore();
			$this->redrawLineNumbersFrom($line-1);
		}
	}

	public function setCurrentLine($text) {
		return $this->setLine($this->line, $text);
	}

	public function setLine($line, $text) {
		if($line > $this->bufferLength + 1) {
			throw new Exception("Trying to set a line beyond the end of the file + 1");
		} else if($line < 1) {
			throw new Exception("Trying to set a line before the beginning (which is 1)");
		}
		if(strpos($line, "\n")!==FALSE || strpos($line, "\r")!==FALSE) {
			throw new Exception('Can\'t insert line endings (\r or \n) anywhere in the buffer. Use standard API to manipulate content.');
		}
		$this->dirty = TRUE;
		$this->buffer[$line-1] = $text;
		$this->bufferLength = sizeof($this->buffer);
		$realLine = $line + $this->drawLine - $this->scrollTop - 1;
		if($realLine >= $this->drawLine && $realLine <= $this->drawLine + $this->drawLines) {
			$this->redrawLine($line);
		}
	}

	public function getFilename() {
		return $this->filename;
	}

	public function getCurrentLine($ignoreBounds=FALSE) {
		return $this->getLine($this->line, $ignoreBounds);
	}

	public function getLine($line, $ignoreBounds=FALSE) {
		if(!$ignoreBounds && $line > $this->bufferLength) {
			throw new Exception("Trying to get a line beyond the end of the file");
		} else if(!$ignoreBounds && $line < 1) {
			throw new Exception("Trying to get a line before the beginning (which is 1)");
		}
		if(!isset($this->buffer[$line-1]))
			return '';
		return $this->buffer[$line-1];
	}

	public function getLineNumber() {
		return $this->line;
	}

	public function getCol() {
		return $this->col;
	}

	public function getLineCount() {
		return $this->bufferLength;
	}

	public function getCursorLine() {
		return $this->drawLine + $this->line - 1 - $this->scrollTop;
	}

	public function getCursorCol() {
		return $this->drawCol + $this->col + 4;
	}

	public function getCurrentChar() {
		if($this->line > $this->bufferLength) return '';
		$l = $this->getCurrentLine();
		if($this->col == mb_strlen($l) + 1) return "\n";
		return mb_substr($l, $this->col-1, 1);
	}

	public function redraw() {
		$this->draw($this->drawLine, $this->drawCol, $this->drawLines, $this->drawCols);
	}

	public function draw($line, $col, $lines, $cols) {
		$this->drawLine = $line;
		$this->drawCol = $col;
		$this->drawLines = $lines;
		$this->drawCols = $cols;
		for($l = 0; $l < $lines; $l++) {
			$this->redrawLine($l + $this->scrollTop + 1);
		}
		$this->placeCursor();
	}

	/**
	*	Expects $this->line
	*/
	public function redrawLine($line) {
		if($line < 1) {
			throw new Exception("Trying to draw line number $line. First line is 1.");
		}
		$cursorLine = $line - $this->scrollTop - 1;
		$cursorLine += $this->drawLine;
		Csi::cursorTo($cursorLine, $this->drawCol);
		$lw = new LineWriter();
		if($line > $this->bufferLength) {
			$lw->addLeft(" ");
//			$lw->addRight($line, 4);
		} else {
			$text = $this->getLine($line);
			$lw->addRight($line, 4);
			$lw->addWhite(1);
			$lw->addLeft(rtrim($text));
		}
		$lw->draw($this->drawCols - $this->drawCol + 1);
//usleep(25000);
		$this->placeCursor();
	}

	public function redrawLineNumbersFrom($line) {
		$firstLine = $line;
		$lastLine = $this->scrollTop + $this->drawLines;

		for($i = $firstLine; $i <= $lastLine; $i++) {
			$this->redrawLineNumber($i);
//usleep(10000);
		}
	}

	public function redrawLineNumber($line) {
		$cursorLine = $line - $this->scrollTop - 1;
		$cursorLine += $this->drawLine;
		Csi::cursorTo($cursorLine, $this->drawCol);
		$lw = new LineWriter();
		if($line > $this->bufferLength) {
		} else {
			$lw->addRight($line, 4);
		}
		$lw->draw(4);
		$this->placeCursor();
	}

	public function placeCursor() {
		$this->scrollIntoView();
		Csi::cursorTo($this->getCursorLine(), $this->getCursorCol());
	}

	public function scrollUp() {
		$oldScroll = $this->scrollTop;
		$this->scrollTop--;
		if($this->scrollTop < 0)
			$this->scrollTop = 0;
		if($oldScroll == $this->scrollTop) {
			// Nothing changed
			return;
		}

		// Fast Redraw
		Csi::store();
		Csi::cursorTo($this->drawLine + $this->drawLines, 1);
		Csi::deleteLine(1);
		Csi::cursorTo($this->drawLine, 1);
		Csi::insertLine(1);
		Csi::restore();
		$this->redrawLine(1 + $this->scrollTop);
	}

	public function scrollDown() {
		$this->scrollTop++;

		// Fast Redraw
		Csi::store();
		Csi::cursorTo($this->drawLine, 1);
		Csi::deleteLine(1);
		Csi::cursorTo($this->drawLine + $this->drawLines - 1, 1);
		Csi::insertLine(1);
		Csi::restore();
		$this->redrawLine($this->scrollTop + $this->drawLines);
	}

	public function scrollIntoView() {
		while(TRUE) {
			$cursorLine = $this->getCursorLine();
			$drawLine = $this->drawLine;
			$drawLines = $this->drawLines;
			$distanceFromTop = $cursorLine - $drawLine;
			$distanceFromBottom = $drawLine + $drawLines - $cursorLine;
			if($distanceFromTop < 0) {
				$this->scrollTop = $distanceFromTop + $this->scrollTop;
				$this->redraw();
				return;
			} else if($distanceFromTop < 5 && $this->scrollTop > 0) {
				// Scroll one up
				$this->scrollUp();
			} else if($distanceFromBottom < 5) {
				// Scroll one down
				$this->scrollDown();
			} else {
				return;
			}
		}
	}
}


class Csi {
	protected static function c($str) {
		echo "\x1B[".$str;
	}

	public static function clearRight() {
		self::c('K');
	}

	public static function clearLeft() {
		self::c('1K');
	}

	public static function clearLine() {
		self::c('2K');
	}

	public static function store() {
		self::c("s");
	}

	public static function restore() {
		self::c("u");
	}

	public static function clear() {
		self::c("2J");
	}

	public static function insertLine($count) {
		self::c($count."L");
	}

	public static function deleteLine($count) {
		self::c($count."M");
	}

	public static function cursorTo($row, $col) {
		self::c($row.";".$col."f");
	}

	public static function backgroundColor($color) {
		self::c((40 + $color).'m');
	}

	public static function color($color) {
		if($color <= 7) {
			self::c((30 + $color).'m');
		} else {
			self::c((90 + $color - 8).'m');
		}
	}
}

class Err {
	public static function end($msg, $code=0) {
		echo $msg;
		die($code);
	}
}

class LineWriter {
	const ALIGN_LEFT = 'left';
	const ALIGN_CENTER = 'center';
	const ALIGN_RIGHT = 'right';

	protected $parts = array();

	public function addWhite($space) {
		$this->add('', $space);
	}

	public function addLeft($text, $space=NULL) {
		$this->add($text, $space, self::ALIGN_LEFT);
	}

	public function addCenter($text, $space=NULL) {
		$this->add($text, $space, self::ALIGN_CENTER);
	}

	public function addRight($text, $space=NULL) {
		$this->add($text, $space, self::ALIGN_RIGHT);
	}

	public function add($text, $space=NULL, $align=self::ALIGN_LEFT) {
		$this->parts[] = array($text, $space, $align);
	}

	public function draw($length) {
		$freeSpace = $length;

		// How much space is already taken?
		$totalContestantLength = 0;
		$contestants = array();
		foreach($this->parts as $key => $part) {
			if($part[1]!==NULL) {
				$freeSpace -= $part[1];
			} else {
				$contestants[] = $key;
			}
		}

		if(sizeof($contestants) > 0) {
			$lengthEach = $freeSpace / sizeof($contestants);
			$offset = 0;
			foreach($contestants as $key) {
				$nextOffset = $offset + $lengthEach;
				$newLength = floor($nextOffset) - floor($offset);
				$offset = $nextOffset;
				$this->parts[$key][1] = $newLength;
			}
		}

		// Draw all parts
		foreach($this->parts as $part) {
			$text = str_replace(chr(27), chr(201), $part[0]);
			$textLen = mb_strlen($text);
			$maxLen = $part[1];

			if($textLen > $maxLen) {
				$text = mb_substr($text, 0, $maxLen-1).'â€¦';
			}

			switch($part[2]) {
				case self::ALIGN_LEFT:
					echo mb_str_pad($text, $maxLen, " ", STR_PAD_RIGHT);
					break;
				case self::ALIGN_RIGHT:
					echo mb_str_pad($text, $maxLen, " ", STR_PAD_LEFT);
					break;
				case self::ALIGN_CENTER:
					echo mb_str_pad($text, $maxLen, " ", STR_PAD_BOTH);
					break;
			}
		}
	}
}

class Token {
	const DIGIT = 'digit';
	const ALPHA = 'alpha';
	const SYMBOL = 'symbol';
	const WHITESPACE = 'whitespace';

	public static function getType($char) {
		if(ctype_alpha($char)) return self::ALPHA;
		if(ctype_digit($char)) return self::DIGIT;
		if(trim($char)==='') return self::WHITESPACE;
		return self::SYMBOL;
	}
}


function mb_str_pad($str, $pad_len, $pad_str = ' ', $dir = STR_PAD_RIGHT, $encoding = NULL)
{
    $encoding = $encoding === NULL ? mb_internal_encoding() : $encoding;
    $padBefore = $dir === STR_PAD_BOTH || $dir === STR_PAD_LEFT;
    $padAfter = $dir === STR_PAD_BOTH || $dir === STR_PAD_RIGHT;
    $pad_len -= mb_strlen($str, $encoding);
    $targetLen = $padBefore && $padAfter ? $pad_len / 2 : $pad_len;
    $strToRepeatLen = mb_strlen($pad_str, $encoding);
    $repeatTimes = ceil($targetLen / $strToRepeatLen);
    $repeatedString = str_repeat($pad_str, max(0, $repeatTimes)); // safe if used with valid utf-8 strings
    $before = $padBefore ? mb_substr($repeatedString, 0, floor($targetLen), $encoding) : '';
    $after = $padAfter ? mb_substr($repeatedString, 0, ceil($targetLen), $encoding) : '';
    return $before . $str . $after;
}
