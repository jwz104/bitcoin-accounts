<?php

namespace Jwz104\BitcoinAccounts;

use Jwz104\BitcoinAccounts\Exceptions;

use Jwz104\BitcoinAccounts\Models\BitcoinUser;
use Jwz104\BitcoinAccounts\Models\BitcoinAddress;
use Jwz104\BitcoinAccounts\Models\BitcoinTransaction;

use Jwz104\BitcoinAccounts\Transaction\Transaction;

use Jwz104\BitcoinAccounts\Exceptions\CommandFailedException;
use Jwz104\BitcoinAccounts\Exceptions\LowBalanceException;

class BitcoinAccounts {

    /**
     * The RPC connection
     *
     * @var \Nbobtc\Http\Client
     */
    protected $connection;

    /**
     * Instantiate a new HomepageController instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->connection = new \Nbobtc\Http\Client($this->getConnectionAddress());
    }

    /**
     * Get the connection address from config
     *
     * @return string
     */
    protected function getConnectionAddress()
    {
        $connectionconfig = config('bitcoinaccounts.connection');

        $url = [];
        $url[] = 'http://';
        $url[] = $connectionconfig['username'];
        $url[] = ':';
        $url[] = $connectionconfig['password'];
        $url[] = '@';
        $url[] = $connectionconfig['host'];
        $url[] = ':';
        $url[] = $connectionconfig['port'];

        return implode($url);
    }

    /**
     * Execute a bitcoind command and get the output
     *
     * @param String $command The API command
     * @param mixed $params The command parameters
     * @return mixed
     */
    public function executeCommand($command, ...$params)
    {
        $command = new \Nbobtc\Command\Command($command, $params);
        $response = $this->connection->sendCommand($command);

        //Throw exception when command fails
        if ($response->getStatusCode() != 200) {
            throw new CommandFailedException($response->getStatusCode(), $response->getBody()->getContents());
        }

        return json_decode($response->getBody()->getContents(), true)['result'];
    }

    /**
     * Find an account by name
     *
     * @param $name string The account name
     * @return Jwz104\BitcoinAccounts\Models\BitcoinUser
     */
    public function getAccount($name)
    {
        return BitcoinUser::where('name', $name)->first();
    }

    /**
     * Find an account by id
     *
     * @param $id int The account id
     * @return Jwz104\BitcoinAccounts\Models\BitcoinUser
     */
    public function findAccount($id)
    {
        return BitcoinUser::find($id);
    }

    /**
     * Create an account and return it
     *
     * @param $name string The account name
     * @return Jwz104\BitcoinAccounts\Models\BitcoinUser
     */
    public function createAccount($name)
    {
        $user = new BitcoinUser();

        $user->name = $name;

        $user->save();

        //Auto create an address
        if (config('bitcoinaccounts.account.autocreate-address') == true) {
            $this->createAddress($user);
        }

        return $user;
    }

    /**
     * Return the account that belongs to the name or create one
     *
     * @param $name string The account name
     * @return Jwz104\BitcoinAccounts\Models\BitcoinUser
     */
    public function getOrCreateAccount($name)
    {
        if (($user = $this->getAccount($name)) != null) {
            return $user;
        }

        return $this->createAccount($name);
    }

    /**
     * Create an address for an account and return the id
     *
     * @param $user Jwz104\BitcoinAccounts\Models\BitcoinUser The user
     * @return bool
     */
    public function createAddress(BitcoinUser $user = null)
    {
        $address = $this->executeCommand('getnewaddress');

        $bitcoinaddress = new BitcoinAddress();

        $bitcoinaddress->bitcoin_user_id = ($user == null) ? null : $user->id;
        $bitcoinaddress->address = $address;

        $bitcoinaddress->save();

        return $bitcoinaddress->id;
    }

    /**
     * Remove an account
     *
     * @param $user int The account
     * @return void
     */
    public function deleteAccount(BitcoinUser $user)
    {
        if ($user->transactions->count() > 0 || $user->addresses->count() > 0) {
            $user->delete();
        }
        //Force delete because this user doesn't have any relations
        $user->forceDelete();
    }

    /**
     * Merge account together, the second account will be removed,
     * and the addresses and balance will go to the first account.
     * Return true if successfull
     *
     * @param $id int The account id
     * @return bool
     */
    public function mergeAccount(BitcoinUser $user, BitcoinUser $mergeuser)
    {
        //Transfer all the address and transactions
        BitcoinAddress::where('bitcoin_user_id', $mergeuser->id)->update('bitcoin_user_id', $user->id);
        BitcoinTransaction::where('bitcoin_user_id', $mergeuser->id)->update(['bitcoin_user_id' => $user->id]);
        BitcoinTransaction::where('other_bitcoin_user_id', $mergeuser->id)->update(['other_bitcoin_user_id' => $user->id]);

        //Force delete because this user doesn't have any relations
        $mergeuser->forceDelete();
        return true;
    }

    /**
     * Set the user for an address and return true is successfull
     *
     * @param $user Jwz104\BitcoinAccounts\Models\BitcoinUser
     * @param $address Jwz104\BitcoinAccounts\Models\BitcoinAddress
     * @return bool
     */
    public function setAddressUser(BitcoinUser $user, BitcoinAddress $address)
    {
        $address->user_id = $user->id;
        $address->save();
        return true;
    }

    /**
     * Send bitcoins to an user
     *
     * @param $fromuser Jwz104\BitcoinAccounts\Models\BitcoinUser From user
     * @param $touser Jwz104\BitcoinAccounts\Models\BitcoinUser The destination user
     * @param $amount double The amount of bitcoins
     * @return bool
     */
    public function sendToUser(BitcoinUser $fromuser, BitcoinUser $touser, $amount)
    {
        if ($fromuser->balance() < $amount) {
            throw new LowBalanceException();
        }

        $transaction = new BitcoinTransaction();

        $transaction->bitcoin_user_id = $fromuser->id;
        $transaction->amount = $amount;
        $transaction->type = 'account';
        $transaction->other_bitcoin_user_id = $touser->id;

        $transaction->save();
        return true;
    }

    /**
     * Send bitcoins to an address and return the txid
     * the amount of fee defined in the config file will be added to the amount
     *
     * @param $user Jwz104\BitcoinAccounts\Models\BitcoinUser From user
     * @param $address Jwz104\BitcoinAccounts\Models\BitcoinAddress To address
     * @param $amount double The amount of bitcoins
     * @param $fee double The amount of fee, leave empty for default amount
     * @return string
     */
    public function sendToAddress(BitcoinUser $user, $address, $amount, $fee = null)
    {
        $transaction = new Transaction($user, $address, $amount, $fee);

        $transaction->create();
        $transaction->sign();
        return $transaction->send();
    }

    /**
     * List all the unspent bitcoins
     *
     * @return mixed
     */
    public function listUnspent()
    {
        return $this->executeCommand('listunspent');
    }

    /**
     * Create raw transaction and return true if successfull
     *
     * @param $txids mixed[] The transaction ids from listunspent
     * @param $destination mixed[] The address and amount
     * @return bool
     */
    public function createRawTransaction($txids, $destination)
    {
        return $this->executeCommand('createrawtransaction', $txids, $destination);
    }

    /**
     * Decode a raw transaction
     *
     * @param $rawtx string The transaction
     * @return mixed[]
     */
    public function decodeRawTransaction($rawtx)
    {
        return $this->executeCommand('decoderawtransaction', $rawtx);
    }

    /**
     * Sign a raw transaction
     *
     * @param $rawtx string The transaction
     * @return mixed[]
     */
    public function signRawTransaction($rawtx)
    {
        $signedtransaction = $this->executeCommand('signrawtransaction', $rawtx);
        return $signedtransaction['hex'];
    }

    /**
     * Send a raw transaction
     *
     * @param $signedrawtx string The transaction
     * @return mixed[]
     */
    public function sendRawTransaction($signedrawtx)
    {
        return $this->executeCommand('sendrawtransaction', $signedrawtx);
    }

    /**
     * List the transactions and filter out bitcoind user to user transactions
     *
     * @param $rawtx string The transaction
     * @return mixed[]
     */
    public function listTransactions($from, $amount)
    {
        $transactions = collect($this->executeCommand('listtransactions', '*', $amount, $from));
        return $transactions->where('category', '!=', 'move')->where('confirmations', '>', 0);
    }
}
