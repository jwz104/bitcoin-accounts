<?php

namespace Jwz104\BitcoinAccounts\Transaction;

use Jwz104\BitcoinAccounts\Models\BitcoinUser;
use Jwz104\BitcoinAccounts\Models\BitcoinAddress;
use Jwz104\BitcoinAccounts\Models\BitcoinTransaction;

use Jwz104\BitcoinAccounts\Facades\BitcoinAccounts;

class Transaction {

    /**
     * The destination address
     *
     * @var string
     */
    protected $address;

    /**
     * The amount of bitcoins
     *
     * @var double
     */
    protected $amount;

    /**
     * The amount of fee
     *
     * @var double
     */
    protected $fee;

    /**
     * The amount of bitcoins from the full transaction
     *
     * @var double
     */
    protected $transactionamount;

    /**
     * The bitcoin user
     *
     * @var Jwz104\BitcoinAccounts\Models\BitcoinUser
     */
    protected $bitcoinuser;

    /**
     * The raw transaction id
     *
     * @var string
     */
    protected $rawtx;

    /**
     * The signed raw transaction id
     *
     * @var string
     */
    protected $signedrawtx;

    /**
     * Instantiate a new Transaction instance.
     *
     * @param $bitcoinser Jwz104\BitcoinAccounts\Models\BitcoinUser The bitcoin user
     * @param $address string The destination address
     * @param $amount double The amount of bitcoins
     * @param $fee double The amount of fee
     * @return void
     */
    public function __construct(BitcoinUser $bitcoinuser, $address, $amount, $fee = null)
    {
        if ($fee == null) {
            $fee = config('bitcoinaccounts.bitcoin.transaction-fee');
        }
        $this->bitcoinuser = $bitcoinuser;
        $this->address = $address;
        $this->amount = $amount;
        $this->fee = $fee;
    }

    /**
     * Create the transaction and return the raw transaction
     *
     * @return string
     */
    public function createTransaction()
    {
        if ($this->bitcoinuser->balance() < $this->amount || $this->bitcoinuser->addresses->count() < 1) {
            return null;
        }
        //Get all the unspent transactions
        $unspent = collect(BitcoinTransactions::listUnspent())
            ->where('spendable', true)
            ->sortByDesc('amount');

        $txout = [];
        $amount = ($this->amount+$this->fee);
        //Get the required amount of txout to create the transaction
        foreach ($unspent as $transaction) {
            $txout[] = [
                'txid' => $transaction->txid, 
                'vout' => $transaction->vout, 
            ];
            $amount-=$transaction['amount'];
            //Keep going untill there are enough bitcoins
            if ($amount <= 0) {
                break;
            }
        }

        //Check if there is enough balance in unspent
        if ($amount > 0) {
            throw new \Exception('Not enough balance in unspent');
        }

        //Calculate what is paid to much
        $total = collect($txout)->sum('amount');
        $change = $total - ($this->amount+$this->fee);

        $this->transactionamount = $total;

        //Create the raw transaction
        $rawtx = BitcoinAccounts::createRawTransaction($txout, [$this->address => $this->amount, $this->bitcoinuser->addresses->first()->address => $change]);

        return ($this->rawtx = $rawtw);
    }

    /**
     * Decode raw transaction
     *
     * @return mixed[]
     */
    public function decode()
    {
        if ($this->rawtx == null) {
            return null;
        }
        return BitcoinAccounts::decodeRawTransaction($this->rawtx);
    }

    /**
     * Sign the transaction and return signed tx
     *
     * @return string
     */
    public function sign()
    {
        if ($this->rawtx == null) {
            return null;
        }
        return ($this->signedrawtx = BitcoinAccounts::signRawTransaction($this->rawtx));
    }

    /**
     * Send the transaction and return the transaction id
     *
     * @return string
     */
    public function send()
    {
        if ($this->rawtx == null) {
            return null;
        }
        try {
            $txid = $this->signedrawtx = BitcoinAccounts::signRawTransaction($this->rawtx);
        } catch (\Exception $e) {
            return null;
        }

        $bitcointransaction = new BitcoinTransaction();

        $bitcointransaction->user_id = $this->bitcoinuser->id;
        $bitcointransaction->txid = $txid;
        $bitcointransaction->amount = $this->amount;
        $bitcointransaction->fee = $this->fee;
        $bitcointransaction->type = 'sent';
        $bitcointransaction->other_address = $this->address;

        $bitcointransaction->save();

        return $txid;
    }
}
