<?php

/**
 * @file Beloved.php
 * @brief This file contains the Beloved class.
 * @details
 * @author Filippo F. Fadda
 */


namespace ReIndex\Badge\Decorator\Star;


use ReIndex\Badge\Decorator\Decorator;
use ReIndex\Enum\Metal;


/**
 * @brief Wrote a post starred by 10 users.
 * @details Awarded multiple times.
 * @nosubgrouping
 */
class Beloved extends Decorator {


  /**
   * @copydoc Decorator::getMetal()
   */
  public function getMetal() {
    return Metal::SILVER;
  }


  /**
   * @copydoc IObserver::getMessages()
   */
  public function getMessages() {
    return ['star'];
  }


  /**
   * @copydoc IObserver::update()
   * @todo Implements the `update()` method.
   */
  public function update($msg, $data) {

  }

}