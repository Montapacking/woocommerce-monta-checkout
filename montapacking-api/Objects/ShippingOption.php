<?php
include("Option.php");

class MontaCheckout_ShippingOption
{

    public $code;
    public $codes;
    public $optioncodes;
    public $optionsWithValue;
    public $description;
    public $mailbox;
    public $price;
    public $currency;
    public $from;
    public $to;
    public $extras;
    public $date;

    public function __construct($code, $codes, $optioncodes, $optionsWithValue, $description, $mailbox, $price, $currency, $from, $to, $extras, $date)
    {

        $this->setCode($code);
        $this->setCodes($codes);
        $this->setOptionCodes($optioncodes);
        $this->setOptionsWithValue($optionsWithValue);
        $this->setDescription($description);
        $this->setMailbox($mailbox);
        $this->setPrice($price);
        $this->setCurrency($currency);
        $this->setFrom($from);
        $this->setTo($to);
        $this->setExtras($extras);
        $this->setDate($date);

    }

    public function setCode($code)
    {
        $this->code = $code;
        return $this;
    }

    public function setCodes($codes)
    {
        $this->codes = $codes;
        return $this;
    }

    public function setOptionCodes($optioncodes)
    {
        $this->optioncodes = $optioncodes;
        return $this;
    }

    public function setOptionsWithValue($optionsWithValue)
    {
        $this->optionsWithValue = $optionsWithValue;
        return $this;
    }

    public function setDescription($description)
    {
        $this->description = $description;
        return $this;
    }

    public function setMailbox($mailbox)
    {
        $this->mailbox = $mailbox;
        return $this;
    }

    public function setPrice($price)
    {
        $this->price = $price;
        return $this;
    }

    public function setCurrency($currency)
    {
        $this->currency = $currency;
        return $this;
    }

    public function setFrom($from)
    {
        $this->from = $from;
        return $this;
    }

    public function setTo($to)
    {
        $this->to = $to;
        return $this;
    }

    public function setExtras($extras)
    {

        $list = null;

        if (is_array($extras)) {

            foreach ($extras as $extra) {

                $list[] = new MontaCheckout_Option(
                    $extra->Code,
                    $extra->Description,
                    $extra->SellPrice,
                    $extra->SellPriceCurrency
                );

            }

        }

        $this->extras = $list;
        return $this;
    }

    public function setDate($date)
    {
        $this->date = date('Y-m-d H:i:s', strtotime($date));
    }

    public function toArray()
    {

        $option = null;
        foreach ($this as $key => $value) {
            $option[$key] = $value;
        }

        return $option;

    }

}