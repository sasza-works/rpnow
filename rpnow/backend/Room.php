<?php

if(!isset($rpVersion)) die();

require_once 'config.php';
require_once 'Connection.php';

class Room {
  const GENERIC_EXCEPTION = 776690000;
  const ROOM_NOT_FOUND_EXCEPTION = 776690001;
  const INVALID_ROOM_ID_EXCEPTION = 776690002;
  
  private static function GenerateID() {
    require_once 'lib/random_compat/lib/random.php';
    
    global $rpIDLength, $rpIDChars;
    $id = '';
    for ($i = 0; $i < $rpIDLength; $i++) {
      $id .= $rpIDChars[random_int(0, strlen($rpIDChars) - 1)];
    }
    return $id;
  }
  
  public static function IsValidID($id) {
    global $rpIDLength, $rpIDChars;
    return preg_match(
      '/^['.preg_quote($rpIDChars).']{'.$rpIDLength.'}/',
      $id
    );
  }
  
  private $db;
  private $id;
  private $roomNum;
  private $title;
  private $desc;
  private $numMsgs;
  private $numChars;
  
  private function __construct($db, $id, $roomNum, $title, $desc, $numChars, $numMsgs) {
    $this->db = $db;
    $this->id = $id;
    $this->roomNum = $roomNum;
    $this->title = $title;
    $this->desc = $desc;
    $this->numMsgs = $numMsgs;
    $this->numChars = $numChars;
  }
  
  public static function CreateRoom($title, $desc) {
    $conn = RPDatabase::createConnection();
    do {
      $id = Room::GenerateID();
    } while(Room::IDExists($id, $conn));
    $conn
      ->prepare("INSERT INTO `Room` (`ID`, `Title`, `Description`, `IP`) VALUES (?, ?, ?, ?)")
      ->execute(array($id, $title, $desc, $_SERVER['REMOTE_ADDR']));
    $conn->commit();
    $conn = null;
    return $id;
  }
  
  public static function GetRoom($id) {
    if(!Room::IsValidID($id)) {
      throw new Exception("Malformed Room ID: '$id'", Room::INVALID_ROOM_ID_EXCEPTION);
    }
    $conn = RPDatabase::createConnection();
    if(!Room::IDExists($id, $conn)) {
      throw new Exception("Room '$id' does not exist.", Room::ROOM_NOT_FOUND_EXCEPTION);
    }
    $statement = $conn->prepare('
      SELECT `Number`, `Title`, `Description`,
      (SELECT COUNT(*) FROM `Character` WHERE `Character`.`Room_Number` = `Room`.`Number`) AS `CharacterCount`,
      (SELECT COUNT(*) FROM `Message` WHERE `Message`.`Room_Number` = `Room`.`Number`) AS `MessageCount`
      FROM `Room` WHERE `ID` = ?
    ');
    $statement->execute(array($id));
    if($statement->rowCount() == 0) {
      throw new Exception("Room '$id' expected but not found.");
    }
    $row = $statement->fetch();
    return new Room($conn, $id, $row['Number'], $row['Title'], $row['Description'], +$row['CharacterCount'], +$row['MessageCount']);
  }
  
  public function close() {
    RPDatabase::closeGracefully($this->db);
  }
  
  public function getID() { return $this->id; }
  public function getTitle() { return $this->title; }
  public function getDesc() { return $this->desc; }
  public function getMessageCount() { return $this->numMsgs; }
  public function getCharacterCount() { return $this->numChars; }
  
  private static function IDExists($id, $conn) {
    $statement = $conn->prepare("SELECT COUNT(*) FROM `Room` WHERE `ID` = ? LIMIT 1");
    $statement->execute(array($id));
    $row = $statement->fetch();
    return $row[0] == '1';
  }
  
  private static function getIPColors($ip) {
    $md5str = md5($ip);
    return array(
      '#' . substr($md5str, 0, 6),
      '#' . substr($md5str, 6, 6),
      '#' . substr($md5str, 12, 6)
    );
  }
  
  public function getMessages($which, $n = NULL) {
    $room = $this->getID();
    global $rpPostsPerPage;
    $statement = NULL;
    // latest (ppp) messages
    if($which == 'latest') {
      $statement = $this->db->prepare("(SELECT
      `Number`, `Type`, `Content`, UNIX_TIMESTAMP(`Time_Created`) AS `Time_Created`, UNIX_TIMESTAMP(`Time_Updated`) AS `Time_Updated`, `IP`, `Chara_Number`, `Deleted`
      FROM `Message` WHERE `Room_Number` = :room
      ORDER BY `Number` DESC LIMIT :posts)
      ORDER BY `Number` ASC;");
      $statement->bindParam(':posts', $rpPostsPerPage, PDO::PARAM_INT);
    }
    // page of archive
    else if($which == 'page' && !is_null($n)) {
      if(intval($n) == false || intval($n) != floatval($n) || intval($n) < 1) {
        throw new Exception('invalid page number.');
      }
      $n = intval($n);
      if($n > 1 && $n > $this->getNumPages()) {
        throw new Exception('page does not yet exist.');
      }
      $start = ($n - 1) * $rpPostsPerPage;
      $statement = $this->db->prepare("SELECT
      `Number`, `Type`, `Content`, UNIX_TIMESTAMP(`Time_Created`) AS `Time_Created`, UNIX_TIMESTAMP(`Time_Updated`) AS `Time_Updated`, `IP`, `Chara_Number`, `Deleted`
      FROM `Message` WHERE `Room_Number` = :room
      ORDER BY `Number` ASC LIMIT :start, :posts;");
      $statement->bindParam(':start', $start, PDO::PARAM_INT);
      $statement->bindParam(':posts', $rpPostsPerPage, PDO::PARAM_INT);
    }
    // updates
    else if($which == 'after') {
      if(is_null($n)) throw new Exception('value for $n is null.');
      if(intval($n) === false || intval($n) != floatval($n) || intval($n) < 0) {
        throw new Exception("invalid message request: $n is a bad number.");
      }
      $statement = $this->db->prepare("SELECT
      `Number`, `Type`, `Content`, UNIX_TIMESTAMP(`Time_Created`) AS `Time_Created`, UNIX_TIMESTAMP(`Time_Updated`) AS `Time_Updated`, `IP`, `Chara_Number`, `Deleted`
      FROM `Message` WHERE `Room_Number` = :room
      ORDER BY `Number` ASC LIMIT 9999 OFFSET :n");
      $statement->bindParam(':n', intval($n), PDO::PARAM_INT);
    }
    else {
      throw new Exception('unknown message request!');
    }
    // execute the statement selected
    $statement->bindParam(':room', $this->roomNum);
    $statement->execute();
    // also retrieve IP color mapping
    return array_map(
      function($x) {
        return array(
          'Number' => $x['Number'],
          'Content' => $x['Content'],
          'Time_Created' => $x['Time_Created'],
          'Time_Updated' => $x['Time_Updated'],
          'IPColor' => Room::getIPColors($x['IP']),
          'Type' => $x['Type'],
          'Chara_Number' => $x['Chara_Number'],
          'Deleted' => $x['Deleted'],
        );
      },
      $statement->fetchAll()
    );
  }
  
  public function getCharacters($after = 0) {
    if(intval($after) === false || intval($after) != floatval($after) || intval($after) < 0) {
      throw new Exception("invalid character request: $after is a bad number.");
    }
    // get the characters
    $statement = $this->db->prepare("SELECT `Number`, `Name`, `Color`, `IP`, `Deleted` FROM `Character` WHERE `Room_Number` = ? LIMIT 9999 OFFSET $after");
    $statement->execute(array($this->roomNum));
    // calculate the secondary color for each and return in modified array
    return array_map(
      function($x) {
        //YIQ algorithm modified from:
        // http://24ways.org/2010/calculating-color-contrast/
        $prec = floor(strlen($x['Color']) / 3);
        $mult = $prec == 1 ? 17: 1;
        $r = hexdec(substr($x['Color'],1+$prec*0,$prec))*$mult;
        $g = hexdec(substr($x['Color'],1+$prec*1,$prec))*$mult;
        $b = hexdec(substr($x['Color'],1+$prec*2,$prec))*$mult;
        $yiq = (($r*299)+($g*587)+($b*114))/1000;
        return array(
          'Number' => $x['Number'],
          'Name' => $x['Name'],
          'Color' => $x['Color'],
          'Contrast' => ($yiq >= 128) ? 'black' : 'white',
          'IPColor' => Room::getIPColors($x['IP']),
          'Deleted' => $x['Deleted']
        );
      },
      $statement->fetchAll()
    );
  }
  
  public function getNumPages() {
    global $rpPostsPerPage;
    return ceil($this->getMessageCount() / $rpPostsPerPage);
  }
  
  public function getTranscript() {
    // all messages
    $statement = $this->db->prepare('SELECT
    `Type`, `Content`, `Name`
    FROM `Message` LEFT JOIN `Character` ON (`Character`.`Number` = `Message`.`Chara_Number`) WHERE `Message`.`Room_Number` = ?
    ORDER BY `Message`.`Number` ASC;');
    $statement->execute(array($this->roomNum));
    return $statement->fetchAll();
  }
  
  public function getStatsArray() {
    $dataStatement = $this->db->prepare("SELECT
      MAX(`Time_Created`) AS `LatestMessageDate`,
      MIN(`Time_Created`) AS `FirstMessageDate`,
      SUM(if(`Type`='Narrator', 1,0)) AS `NarratorMessageCount`,
      SUM(if(`Type`='OOC', 1,0)) AS `OOCMessageCount`,
      SUM(if(`Type`='Narrator', char_length(`Content`),0)) AS `NarratorCharCount`,
      SUM(if(`Type`='Character', char_length(`Content`),0)) AS `CharacterCharCount`,
      SUM(if(`Type`='OOC', char_length(`Content`),0)) AS `OOCCharCount`
      
      FROM `Message`
      WHERE `Room_Number` = ?"
    );
    $dataStatement->execute(array($this->roomNum));
    $data = $dataStatement->fetch();
    $top5Statement = $this->db->prepare("SELECT `Character`.`Name`, COUNT(*) AS `MessageCount` FROM `Message` LEFT JOIN `Character` ON `Chara_Number`=`Character`.`Number` WHERE `Message`.`Type`='Character' AND `Message`.`Room_Number` = ? GROUP BY `Chara_Number` ORDER BY `MessageCount` DESC LIMIT 5;");
    $top5Statement->execute(array($this->roomNum));
    return array(
      'MessageCount' => $this->getMessageCount(), 'CharacterCount' => $this->getCharacterCount(),
      'FirstMessageDate' => $data['FirstMessageDate'],
      'LatestMessageDate' => $data['LatestMessageDate'],
      'NarratorMessageCount' => $data['NarratorMessageCount'],
      'CharacterMessageCount' => $this->getMessageCount() - $data['NarratorMessageCount'] - $data['OOCMessageCount'],
      'OOCMessageCount' => $data['OOCMessageCount'],
      'NarratorCharCount' => $data['NarratorCharCount'],
      'CharacterCharCount' => $data['CharacterCharCount'],
      'OOCCharCount' => $data['OOCCharCount'],
      'TotalCharCount' => $data['NarratorCharCount'] + $data['CharacterCharCount'] + $data['OOCCharCount'],
      'TopCharacters' => $top5Statement->fetchAll()
    );
  }
  
  public function addMessage($type, $content, $charaNum = null) {
    if(!in_array($type, array('Narrator', 'Character', 'OOC'))) {
      throw new Exception('Invalid type: ' . $type);
    }
    $content = trim($content);
    if(!$content) {
      throw new Exception('Message is empty.');
    }
    $statement = null;
    if($type == 'Character') {
      if(!is_int($charaNum) && !ctype_digit($charaNum)) throw new Exception("$charaNum is not an int.");
      
      // validate charaNum
      $statement = $this->db->prepare('SELECT `Room_Number` FROM `Character` WHERE `Number` = ?');
      $statement->execute(array($charaNum));
      if($statement->rowCount() != 1 || $statement->fetch()['Room_Number'] != $this->roomNum)
        throw new Exception("invalid character number: $charaNum");
      
      $statement = $this->db->prepare("INSERT INTO `Message` (`Type`, `Content`, `Room_Number`, `IP`, `Chara_Number`) VALUES (?, ?, ?, ?, ?)");
      $statement->execute(array($type, $content, $this->roomNum, $_SERVER['REMOTE_ADDR'], $charaNum));
    }
    else {
      $statement = $this->db->prepare("INSERT INTO `Message` (`Type`, `Content`, `Room_Number`, `IP`) VALUES (?, ?, ?, ?)");
      $statement->execute(array($type, $content, $this->roomNum, $_SERVER['REMOTE_ADDR']));
    }
    
  }
  
  public function addCharacter($name, $color) {
    $name = trim($name);
    if(!$name) {
      throw new Exception('Name is empty.');
    }
    if(!preg_match_all('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color)) {
      throw new Exception("$color is not a valid hex color.");
    }
    $statement = $this->db->prepare("INSERT INTO `Character` (`Name`, `Room_Number`, `Color`, `IP`) VALUES (?, ?, ?, ?)");
    $statement->execute(array($name, $this->roomNum, $color, $_SERVER['REMOTE_ADDR']));
  }
}

?>