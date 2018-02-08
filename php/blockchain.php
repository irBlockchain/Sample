<?php
	function save_chain_to_db(){
		global $my_blockchain;
		file_put_contents('bc.db', json_encode($my_blockchain));
	}
	
	function load_chain_from_db(){
		global $my_blockchain;
		$my_blockchain = json_decode(file_get_contents('bc.db'), true);
	}
	
	function new_block( $proof, $previous_hash=''){
		global $my_blockchain;
		$block = array(
		    'index' => count($my_blockchain['chain']) + 1,
		    'previous_hash'=> ($previous_hash!=''? $previous_hash: hash_sha256(end($my_blockchain['chain']))),
		    'proof'=> $proof,
		    'timestamp'=> date('U'),
		    'transactions'=> $my_blockchain['current_transactions']
		);
		$my_blockchain['current_transactions'] = array();
		array_push($my_blockchain['chain'], $block);
		
		return $block;
	}
	
	function hash_sha256($block){
		$block_string = json_encode($block);
        	return hash('sha256', $block_string);
	}
	
	function full_chain($type=0){
		global $my_blockchain;
		if($type==0){
			echo str_replace(array("\n", " "), 
				array("<br>", "&nbsp;"), 
				json_encode($my_blockchain['chain'],  JSON_PRETTY_PRINT)
			);
		}
		else{
			echo json_encode($my_blockchain['chain']);
			exit();
		}
	}
	
	function valid_proof($last_proof, $proof, $last_hash){
		$guess = $last_proof.$proof.$last_hash;
        	$guess_hash = hash('sha256',$guess);
        	if(substr($guess_hash, 0, 4) === "0000")
        		return true;
        	return false;
	}
	
	function proof_of_work($last_block){
		$last_proof = $last_block['proof'];
		$last_hash = hash_sha256($last_block);

		$proof = 0;
		while(valid_proof($last_proof, $proof, $last_hash) == false)
		    $proof++;
		return $proof;
	}
	
	function new_transaction($sender, $recipient, $amount){
		global $my_blockchain;
		array_push($my_blockchain['current_transactions'], 
			array(
				'sender' => $sender,
				'recipient' => $recipient,
				'amount' => $amount
			)
		);
		$last_block = end($my_blockchain['chain']);
		return $last_block['index'] + 1;
	}
	
	function mine(){
		global $my_blockchain;
		$last_block = end($my_blockchain['chain']);
		$proof = proof_of_work($last_block);
		new_transaction("0", $my_blockchain['uuid'], 1);
		
		$previous_hash = hash_sha256($last_block);
    		$block = new_block($proof, $previous_hash);
		echo "new block added<br>";
		print_r($block);
	}
	
	function register_nodes($node_addr){
		global $my_blockchain;
		array_push($my_blockchain['nodes'], $node_addr);
	}
	
	function valid_chain($chain){
		global $my_blockchain;
		$last_block = $my_blockchain['chain'][0];
        	$current_index = 1;
        	$total_block = count($my_blockchain['chain']);
		while($current_index < $total_block){
			$block = $my_blockchain['chain'][$current_index];
			
			# Check that the hash of the block is correct
			if($block['previous_hash'] != hash_sha256($last_block))
				return false;

			# Check that the Proof of Work is correct
			if(!valid_proof($last_block['proof'], $block['proof'], $block['previous_hash']))
				return false;

			$last_block = $block;
			$current_index++;
		}
		return true;
	}

	function resolve_conflicts(){
		global $my_blockchain;
		$neighbours = $my_blockchain['nodes'];
		$new_chain = array();

		# We're only looking for chains longer than ours
		$my_length = count($my_blockchain['chain']);

		# Grab and verify the chains from all the nodes in our network
		foreach($neighbours as $node){
			$chain = json_decode(file_get_contents($node.'?action=chain&type=1'), true);
			$length = count($chain);

			# Check if the length is longer and the chain is valid
			if($length > $my_length and valid_chain($chain)){
			    $my_length = $length;
			    $new_chain = $chain;
			}
		}
		# Replace our chain if we discovered a new, valid chain longer than ours
		if (count($new_chain)>0){
			$my_blockchain['chain'] = $new_chain;
			return true;
		}
		return false;
	}

/********************************************************/
/********************START*******************************/
/********************************************************/

	//Check for db
	if(file_exists('bc.db')){
		//load chain from file
		load_chain_from_db();
	}
	else{
		//new chain init
		$my_blockchain = array(
			'chain' => array(),
			'current_transactions' => array(),
			'nodes' => array(),
			'uuid' =>uniqid()
		);
		//add first block
		new_block(100, 1);
		//save new chain
		save_chain_to_db();
	}
	
	if(isset($_GET['action']) and $_GET['action']=='chain'){
		$type=isset($_GET['type'])?$_GET['type']:0;
		full_chain($type);
	}
	elseif(isset($_GET['action']) and $_GET['action']=='mine'){
		mine();
	}
	elseif(isset($_GET['action']) and $_GET['action']=='new_trans'){
		echo '<form method="post" action="?"><br>
			sender: <input type="text" name="sender"><br>
			recipient: <input type="text" name="recipient"><br>
			amount:<input type="text" name="amount"><br>
			<input type="submit" name="commit" value="commit">
		</form>';
	}
	elseif(isset($_GET['action']) and $_GET['action']=='reg_node'){
		echo '<form method="post" action="?"><br>
			Node URL: <input type="text" name="node_addr">
			<input type="submit" name="new_node" value="save">
		</form>';
	}
	elseif(isset($_GET['action']) and $_GET['action']=='nodes'){
		print_r(str_replace(array("\n", "\t"), 
				array("<br>", "&nbsp;&nbsp;&nbsp;&nbsp;"), 
				json_encode($my_blockchain['nodes'],  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
			));
	}
	elseif(isset($_GET['action']) and $_GET['action']=='resolve'){
		if(resolve_conflicts())
			echo 'Our chain was replaced';
		else
			echo 'Our chain is authoritative';
	}
	elseif(isset($_POST['commit'])){
		if(!empty($_POST['sender']) and !empty($_POST['recipient']) and !empty($_POST['amount'])){
			$block_index = new_transaction($_POST['sender'], $_POST['recipient'], $_POST['amount']);
			echo 'Your transaction add to '.$block_index.'th block, and approved after mine :D';
		}
	}
	elseif(isset($_POST['new_node'])){
		register_nodes($_POST['node_addr']);
		echo 'New node added!';
	}
	else{
		echo '<a href="?action=chain">show chain</a><br>';
		echo '<a href="?action=mine">mine</a><br><br>';
		echo '<a href="?action=new_trans">add new transaction</a><br><br>';
		echo '<a href="?action=reg_node">add new node</a><br>';
		echo '<a href="?action=nodes">show nodes list</a><br>';
		echo '<a href="?action=resolve">resolve conflict</a><br>';
	}
	echo '<br><br><br><hr><a href="?">home</a>';
	
	//save chain to file
	save_chain_to_db();
?>
