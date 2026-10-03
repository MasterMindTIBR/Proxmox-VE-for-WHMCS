<?php /* vim: set ts=2 sw=2 tw=0 et :*/

if (!defined('WHMCS')) {
  die('This file cannot be accessed directly');
}

/**
 * Ipv4_SubnetIterator 
 * An object that implements a subnet iterator
 * 
 * @uses Iterator
 * @package Ipv4
 * @version $id$
 * @copyright 2012 Kelly Hallman
 * @author Kelly Hallman
 * @license MIT
 */
class Ipv4_SubnetIterator implements Iterator
{
  private $position = 0;
  private $low_dec;
  private $hi_dec;

  public function __construct(Ipv4_Subnet $subnet) {
    $this->low_dec = ip2long($subnet->getFirstHostAddr());
    $this->hi_dec = ip2long($subnet->getLastHostAddr());
  }

  public function rewind(): void {
    $this->position = 0;
  }

  public function current(): mixed {
    return long2ip($this->low_dec + $this->position);
  }

  public function key(): mixed {
    return $this->position;
  }

  public function next(): void {
    ++$this->position;
  }

  public function valid(): bool {
    return (($this->low_dec + $this->position) <= $this->hi_dec);
  }
}

