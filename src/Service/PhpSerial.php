<?php

namespace App\Service;

define("SERIAL_DEVICE_NOTSET", 0);
define("SERIAL_DEVICE_SET", 1);
define("SERIAL_DEVICE_OPENED", 2);

/**
 * Serial port control class.
 *
 * THIS PROGRAM COMES WITH ABSOLUTELY NO WARANTIES !
 * USE IT AT YOUR OWN RISKS !
 *
 * @author Thomas VELU <velu.thomas77@laposte.net>
 * @thanks Rémy Sanchez <thenux@gmail.com>
 * @thanks Aurélien Derouineau for finding how to open serial ports with windows
 * @thanks Alec Avedisyan for help and testing with reading
 * @thanks Jim Wright and Rizwan Kassim for OSX cleanup/fixes.
 * @copyright under GPL 2 licence
 */
class PhpSerial {

  //phpcs:disable
  public $device = NULL;
  public $windevice = NULL;
  public $dHandle = NULL;
  public $dState = SERIAL_DEVICE_NOTSET;
  public string $buffer = "";
  public string $os = "";
  // This var says if buffer should be flushed by sendMessage (true) or manualy (false)
  public $autoflush = TRUE;
  //phpcs:enable

  /**
   * Constructor. Perform some checks about the OS and setserial.
   */
  public function __construct() {
    setlocale(LC_ALL, "en_US");

    $sysname = php_uname();

    if (substr($sysname, 0, 5) === "Linux") {
      $this->os = "linux";

      if ($this->exec("stty --version") === 0) {
        register_shutdown_function([$this, "deviceClose"]);
      }
      else {
        trigger_error("No stty availible, unable to run.", E_USER_ERROR);
      }
    }
    elseif (substr($sysname, 0, 6) === "Darwin") {
      $this->os = "osx";
      register_shutdown_function([$this, "deviceClose"]);
    }
    elseif (substr($sysname, 0, 7) === "Windows") {
      $this->os = "windows";
      register_shutdown_function([$this, "deviceClose"]);
    }
    else {
      trigger_error("Host OS is neither osx, linux nor windows, unable to run.", E_USER_ERROR);
      exit();
    }
  }

  //
  // OPEN/CLOSE DEVICE SECTION -- {START}
  // .

  /**
   * Device set function : used to set the device name/address.
   *
   * -> linux : use the device address, like /dev/ttyS0
   * -> osx : use the device address, like /dev/tty.serial
   * -> windows : use the COMxx device name, like COM1
   * (can also be used with linux).
   */
  public function deviceSet(string $device) : bool {
    if ($this->dState !== SERIAL_DEVICE_OPENED) {
      switch ($this->os) {
        case 'linux':
          if (preg_match("@^COM(\d+):?$@i", $device, $matches)) {
            $device = "/dev/ttyS" . ($matches[1] - 1);
          }
          $cmd = "stty -F {$device}";
          break;

        case 'osx':
          $cmd = "stty -f {$device}";
          break;

        case 'windows':
          if (
              preg_match("@^COM(\d+):?$@i", $device, $matches)
              && $this->exec(exec("mode {$device} xon=on BAUD=9600")) === 0
          ) {
            $this->windevice = "COM{$matches[1]}:";
            $this->device = "\\\\.\com{$matches[1]}";
            $this->dState = SERIAL_DEVICE_SET;
          }
          break;

        default:
          $cmd = FALSE;
          break;
      }
      if ($cmd && $this->exec($cmd) === 0) {
        $this->device = $device;
        $this->dState = SERIAL_DEVICE_SET;
        return TRUE;
      }

      trigger_error("Specified serial port `$device` is not valid", E_USER_WARNING);

      return FALSE;
    }
    else {
      trigger_error("You must close your device before to set an other one", E_USER_WARNING);

      return FALSE;
    }
  }

  /**
   * Opens the device for reading and/or writing.
   */
  public function deviceOpen(string $mode = "r+b") : bool {
    if ($this->dState === SERIAL_DEVICE_OPENED) {
      trigger_error("The device is already opened", E_USER_NOTICE);
      return TRUE;
    }

    if ($this->dState === SERIAL_DEVICE_NOTSET) {
      trigger_error("The device must be set before to be open", E_USER_WARNING);
      return FALSE;
    }

    if (!preg_match("@^[raw]\+?b?$@", $mode)) {
      trigger_error("Invalid opening mode: `$mode`. Use fopen() modes.", E_USER_WARNING);
      return FALSE;
    }

    $this->dHandle = @fopen($this->device, $mode);

    if ($this->dHandle !== FALSE) {
      stream_set_blocking($this->dHandle, 0);
      $this->dState = SERIAL_DEVICE_OPENED;

      return TRUE;
    }

    $this->dHandle = NULL;
    trigger_error("Unable to open the device", E_USER_WARNING);

    return FALSE;
  }

  /**
   * Sets the I/O blocking or not blocking.
   */
  public function setBlocking(bool $blocking) : void {
    stream_set_blocking($this->dHandle, $blocking);
  }

  /**
   * Closes the device.
   */
  public function deviceClose() : bool {
    if ($this->dState !== SERIAL_DEVICE_OPENED) {
      return TRUE;
    }

    if (fclose($this->dHandle)) {
      $this->dHandle = NULL;
      $this->dState = SERIAL_DEVICE_SET;

      return TRUE;
    }

    trigger_error("Unable to close the device", E_USER_ERROR);

    return FALSE;
  }

  //
  // OPEN/CLOSE DEVICE SECTION -- {STOP}
  // .
  //
  // CONFIGURE SECTION -- {START}
  // .

  /**
   * Configure the Baud Rate.
   *
   * Possible rates: 110, 150, 300, 600, 1200, 2400, 4800, 9600, 38400,
   * 57600, 115200, 230400, 460800, 500000, 576000, 921600, 1000000,
   * 1152000, 1500000, 2000000, 2500000, 3000000, 3500000 and 4000000.
   */
  public function confBaudRate(int $rate) : bool {
    if ($this->dState !== SERIAL_DEVICE_SET) {
      trigger_error("Unable to set the baud rate : the device is either not set or opened", E_USER_WARNING);

      return FALSE;
    }

    $validBauds = [
      110    => 11,
      150    => 15,
      300    => 30,
      600    => 60,
      1200   => 12,
      2400   => 24,
      4800   => 48,
      9600   => 96,
      19200  => 19,
    ];

    $extraBauds = [
      38400, 57600, 115200, 230400, 460800, 500000,
      576000, 921600, 1000000, 1152000, 1500000, 2000000, 2500000, 3000000,
      3500000, 4000000,
    ];

    foreach ($extraBauds as $extraBaud) {
      $validBauds[$extraBaud] = $extraBaud;
    }

    if (isset($validBauds[$rate])) {
      switch ($this->os) {
        case 'linux':
          $cmd = "stty -F {$this->device} $rate";
          break;

        case 'osx':
          $cmd = "stty -f {$this->device} $rate";
          break;

        case 'windows':
          $cmd = "mode {$this->windevice} BAUD={$validBauds[$rate]}";
          break;

        default:
          $cmd = FALSE;
          break;
      }
      if ($cmd) {
        print "$cmd\n";
        $ret = $this->exec("$cmd", $out);
      }

      if ($ret !== 0) {
        trigger_error("Unable to set baud rate: {$out[1]}", E_USER_WARNING);

        return FALSE;
      }
      return TRUE;
    }
    else {
      trigger_error("Unknown baud rate: `$rate`");
      return FALSE;
    }
  }

  /**
   * Configure parity.
   */
  public function confParity(string $parity) : bool {
    if ($this->dState !== SERIAL_DEVICE_SET) {
      trigger_error("Unable to set parity : the device is either not set or opened", E_USER_WARNING);

      return FALSE;
    }

    $args = [
      "none" => "-parenb",
      "odd"  => "parenb parodd",
      "even" => "parenb -parodd",
    ];

    if (!isset($args[$parity])) {
      trigger_error("Parity mode not supported", E_USER_WARNING);

      return FALSE;
    }

    if ($this->os === "linux") {
      $ret = $this->exec("stty -F {$this->device} {$args[$parity]}", $out);
    }
    elseif ($this->os === "osx") {
      $ret = $this->exec("stty -f {$this->device} {$args[$parity]}", $out);
    }
    else {
      $ret = $this->exec("mode {$this->windevice}  PARITY={$parity[0]}", $out);
    }

    if ($ret === 0) {
      return TRUE;
    }

    trigger_error("Unable to set parity : {$out[1]}", E_USER_WARNING);

    return FALSE;
  }

  /**
   * Sets the length of a character. 5 <= length <= 8.
   */
  public function confCharacterLength(int $int) : bool {
    if ($this->dState !== SERIAL_DEVICE_SET) {
      trigger_error(
            "Unable to set length of a character : the device is either not set or opened",
            E_USER_WARNING
        );

      return FALSE;
    }

    $int = (int) $int;
    if ($int < 5) {
      $int = 5;
    }
    elseif ($int > 8) {
      $int = 8;
    }

    if ($this->os === "linux") {
      $ret = $this->exec("stty -F " . $this->device . " cs" . $int, $out);
    }
    elseif ($this->os === "osx") {
      $ret = $this->exec("stty -f " . $this->device . " cs" . $int, $out);
    }
    else {
      $ret = $this->exec("mode " . $this->windevice . " DATA=" . $int, $out);
    }

    if ($ret === 0) {
      return TRUE;
    }

    trigger_error("Unable to set character length : " . $out[1], E_USER_WARNING);

    return FALSE;
  }

  /**
   * Sets the length of stop bits.
   *
   * Length of a stop bit. It must be either 1,
   * 1.5 or 2. 1.5 is not supported under linux and on some computers.
   */
  public function confStopBits($length) : bool {
    if ($this->dState !== SERIAL_DEVICE_SET) {
      trigger_error(
            "Unable to set the length of a stop bit : the device is either not set or opened",
            E_USER_WARNING
        );

      return FALSE;
    }

    if ($length != 1 && $length != 2 && $length != 1.5 && !($length == 1.5 and $this->os === "linux")) {
      trigger_error("Specified stop bit length is invalid", E_USER_WARNING);

      return FALSE;
    }

    if ($this->os === "linux") {
      $ret = $this->exec("stty -F " . $this->device . " " . (($length == 1) ? "-" : "") . "cstopb", $out);
    }
    elseif ($this->os === "osx") {
      $ret = $this->exec("stty -f " . $this->device . " " . (($length == 1) ? "-" : "") . "cstopb", $out);
    }
    else {
      $ret = $this->exec("mode " . $this->windevice . " STOP=" . $length, $out);
    }

    if ($ret === 0) {
      return TRUE;
    }

    trigger_error("Unable to set stop bit length : " . $out[1], E_USER_WARNING);

    return FALSE;
  }

  /**
   * Configures the flow control.
   *
   * @param string $mode
   *   Set the flow control mode. Availible modes :
   *   -> "none" : no flow control
   *   -> "rts/cts" : use RTS/CTS handshaking
   *   -> "xon/xoff" : use XON/XOFF protocol.
   */
  public function confFlowControl(string $mode) : bool {
    if ($this->dState !== SERIAL_DEVICE_SET) {
      trigger_error("Unable to set flow control mode : the device is either not set or opened", E_USER_WARNING);

      return FALSE;
    }

    $linuxModes = [
      "none"     => "clocal -crtscts -ixon -ixoff",
      "rts/cts"  => "-clocal crtscts -ixon -ixoff",
      "xon/xoff" => "-clocal -crtscts ixon ixoff",
    ];
    $windowsModes = [
      "none"     => "xon=off octs=off rts=on",
      "rts/cts"  => "xon=off octs=on rts=hs",
      "xon/xoff" => "xon=on octs=off rts=on",
    ];

    if ($mode !== "none" and $mode !== "rts/cts" and $mode !== "xon/xoff") {
      trigger_error("Invalid flow control mode specified", E_USER_ERROR);

      return FALSE;
    }

    if ($this->os === "linux") {
      $ret = $this->exec("stty -F {$this->device}  {$linuxModes[$mode]}", $out);
    }
    elseif ($this->os === "osx") {
      $ret = $this->exec("stty -f {$this->device} {$linuxModes[$mode]}", $out);
    }
    else {
      $ret = $this->exec("mode {$this->windevice} $windowsModes[$mode]}", $out);
    }
    if ($ret === 0) {
      return TRUE;
    }
    else {
      trigger_error("Unable to set flow control : {$out[1]}", E_USER_ERROR);

      return FALSE;
    }
  }

  /**
   * Sets a setserial parameter (cf man setserial)
   *
   * NO MORE USEFUL !
   *  -> No longer supported
   *  -> Only use it if you need it.
   */
  public function setSetserialFlag(string $param, string $arg = "") : bool {
    if (!$this->ckOpened()) {
      return FALSE;
    }

    $return = exec("setserial {$this->device} $param $arg 2>&1");

    if ($return[0] === "I") {
      trigger_error("setserial: Invalid flag", E_USER_WARNING);

      return FALSE;
    }
    elseif ($return[0] === "/") {
      trigger_error("setserial: Error with device file", E_USER_WARNING);

      return FALSE;
    }
    else {
      return TRUE;
    }
  }

  //
  // CONFIGURE SECTION -- {STOP}
  // .
  //
  // I/O SECTION -- {START}
  // .

  /**
   * Sends a string to the device.
   *
   * $str string to be sent to the device.
   * $waitForReply time to wait for the reply (in seconds)
   */
  public function sendMessage(string $str, float $waitForReply = 0.1) {
    $this->buffer .= $str;

    if ($this->autoflush === TRUE) {
      $this->serialflush();
    }

    usleep((int) ($waitForReply * 1000000));
  }

  /**
   * Reads one line and returns after a \r or \n.
   */
  public function readLine() : string {
    $line = '';

    $this->setBlocking(TRUE);
    while (TRUE) {
      $c = $this->readPort(1);

      if ($c != "\r" && $c != "\n") {
        $line .= $c;
      }
      else {
        if ($line) {
          break;
        }
      }
    }
    $this->setBlocking(FALSE);

    return $line;
  }

  /**
   * Flush.
   */
  public function readFlush() {
    while ($this->dataAvailable()) {
      $this->readPort(1);
    }
  }

  /**
   * Available.
   */
  public function dataAvailable() {
    $read = [$this->dHandle];
    $write = NULL;
    $except = NULL;

    return stream_select($read, $write, $except, 0);
  }

  /**
   * Reads the port until no new datas are availible, then return the content.
   *
   * $count number of characters to be read
   * (will stop before if less characters are in the buffer).
   */
  public function readPort(int $count = 0) : string {
    if ($this->dState !== SERIAL_DEVICE_OPENED) {
      trigger_error("Device must be opened to read it", E_USER_WARNING);
      return FALSE;
    }

    // S'assurer que $count est un entier positif ou zéro.
    $count = max(0, (int) $count);
    $content = "";
    // Longueur de lecture par bloc.
    $readLength = 128;

    while ($count === 0 || strlen($content) < $count) {
      $toRead = $count > 0 ? min($readLength, $count - strlen($content)) : $readLength;
      $buffer = fread($this->dHandle, $toRead);
      if ($buffer === FALSE || $buffer === "") {
        break;
      }
      $content .= $buffer;
    }

    return $content;
  }

  /**
   * Flushes the output buffer. Renamed from flush for osx compat.
   */
  public function serialflush() : bool {
    if (!$this->ckOpened()) {
      return FALSE;
    }

    if (fwrite($this->dHandle, $this->buffer) !== FALSE) {
      $this->buffer = "";

      return TRUE;
    }
    else {
      $this->buffer = "";
      trigger_error("Error while sending message", E_USER_WARNING);

      return FALSE;
    }
  }

  //
  // I/O SECTION -- {STOP}
  // .
  //
  // INTERNAL TOOLKIT -- {START}.

  /**
   * CkOpened.
   */
  public function ckOpened() {
    if ($this->dState !== SERIAL_DEVICE_OPENED) {
      trigger_error("Device must be opened", E_USER_WARNING);

      return FALSE;
    }

    return TRUE;
  }

  /**
   * CkClosed.
   */
  public function ckClosed() {

    return TRUE;
  }

  /**
   * Exec.
   */
  public function exec($cmd, &$out = NULL) {
    $desc = [
      1 => ["pipe", "w"],
      2 => ["pipe", "w"],
    ];

    $proc = proc_open($cmd, $desc, $pipes);

    $ret = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $retVal = proc_close($proc);

    if (func_num_args() == 2) {
      $out = [$ret, $err];
    }

    return $retVal;
  }

  //
  // INTERNAL TOOLKIT -- {STOP}
  // .
}
