<?php
App::uses('Component', 'Controller');
App::uses('CartSessionComponent', 'Cart.Controller/Component');
App::uses('CakeEvent', 'Event');
/**
 * CartManagerComponent
 *
 * This component cares just and only about the cart contents. It will add
 * and remove items from the active cart but nothing more.
 * 
 * The component will make sure that the cart content in the sessio and database
 * is always the same and gets merged when a user is not logged in and then logs in.
 *
 * It also can store the cart for non logged in users semi-persistant in a cookie.
 *
 * Checking a cart out and dealing with other stuff is not the purpose of this 
 * component.
 *
 * @author Florian Krämer
 * @copyright Florian Krämer
 * @license MIT
 */
class CartManagerComponent extends Component {
/**
 * Components
 *
 * @var array
 */
	public $components = array(
		'Auth',
		'Session',
		'Cookie',
		'Cart.CartSession');

/**
 * Id of the active database cart
 *
 * @var string Cart UUID
 */
	protected $_cartId = null;

/**
 * User status if logged in or not
 *
 * @var boolean
 */
	public $_isLoggedIn = false;

/**
 * Default settings
 * - model 
 * - buyAction the controller action to use or check for
 * - cartModel 
 * - sessionKey 
 * - useCookie 
 * - cookieName 
 * - afterAddItemRedirect
 *   - false to disable it
 *   - null to use the referer
 *   - string or array to set a redirect url
 * - getBuy boolean enable/disable capture of buy data via get
 * - postBuy boolean enable/disable capture of buy data via post
 *
 * @var array
 */
	protected $_defaultSettings = array(
		'model' => null,
		'buyAction' => 'buy',
		'cartModel' => 'Cart.Cart',
		'cartSession' => array('CartSession', 'Cart.Controller/Component'),
		'sessionKey' => 'Cart',
		'useCookie' => false,
		'cookieName' => 'Cart',
		'afterAddItemRedirect' => true,
		'afterAddItemFailedRedirect' => true,
		'getBuy' => true,
		'postBuy' => true);

/**
 * Default settings
 *
 * @var array
 */
	public function initialize(Controller $Controller) {
		$this->settings = array_merge($this->_defaultSettings, $this->settings);
		$this->Controller = $Controller;

		if (empty($this->settings['model'])) {
			$this->settings['model'] = $this->Controller->modelClass;
		}

		$this->sessionKey = $this->settings['sessionKey'];

		$this->_setLoggedInStatus();
		$this->_initalizeCart();
	}

/**
 * Extend the CartManagerComponent and Override this method if needed
 *
 * @return void
 */
	protected function _setLoggedInStatus() {
		if (is_a($this->Controller->Auth, 'AuthComponent') && $this->Controller->Auth->user()) {
			$this->_isLoggedIn = true;
		}
	}

/**
 * Initializes the cart data from session or database depending on if user is logged in or not and if the cart is present or not
 *
 * @todo Do not forget to merge existing itmes with the ones from the database when the user logs in!
 * @return void
 */
	protected function _initalizeCart() {
		extract($this->settings);
		$userId = $this->Auth->user('id');
		$this->CartModel = ClassRegistry::init($cartModel);

		if (!$this->Session->check($sessionKey)) {
			if ($userId) {
				$this->Session->write($sessionKey, $this->CartModel->getActive($userId));
			} else {
				$this->Session->write($sessionKey, array(
					'Cart' => array(),
					'CartsItem' => array()));
			}
		} else {
			if ($userId && !$this->Session->check($sessionKey . '.Cart.id')) {
				$this->Session->write($sessionKey, $this->CartModel->getActive($userId));
			}
		}
		$this->_cartId = $this->Session->read($sessionKey . '.Cart.id');
	}

/**
 * Component startup callback
 *
 * @return void
 */
	public function startup() {
		extract($this->settings);
		if ($this->Controller->action == $buyAction && !method_exists($this->Controller, $buyAction)) {
			$this->captureBuy();
		}
	}

/**
 * Captures a buy from a post or get request
 *
 * @return mixed False if the catpure failed array with item data on success
 */
	public function captureBuy($returnItem = false) {
		extract($this->settings);

		if ($this->Controller->request->is('get')) {
			$data = $this->getBuy();
		}

		if ($this->Controller->request->is('post')) {
			$data = $this->postBuy();
		}

		if (!$data) {
			if ($afterAddItemFailedRedirect === true) {
				$this->Session->setFlash(__d('cart', 'Failed to buy the item'));
				$this->Controller->redirect($this->Controller->referer());
			}
			return false;
		}

		$item = $this->addItem($data);
		if ($item) {
			if ($returnItem == false) {
				if ($this->Controller->request->is('ajax') || $this->Controller->request->is('json') || $this->Controller->request->is('xml')) {
					$this->Controller->set('item', $item);
					$this->Controller->set('_serialize', array('item'));
				} else {
					$this->afterAddItemRedirect($item);
				}
			}
			return $item;
		}

		if ($afterAddItemFailedRedirect === true) {
			$this->Session->setFlash(__d('cart', 'Failed to buy the item'));
			$this->Controller->redirect($this->Controller->referer());
		}

		return false;
	}

/**
 * Adds additional data here that depends more or less on the controller and we
 * want to be present in the before
 *
 * @param array $data
 * @return array
 */
	protected function _additionalData($data) {
		$data['CartsItem']['user_id'] = $this->Auth->user('id');
		$data['CartsItem']['cart_id'] = $this->_cartId;

		if (!isset($data['CartsItem']['model'])) {
			$data['CartsItem']['model'] = $this->settings['model'];
		}

		if (empty($data['CartsItem']['quantity'])) {
			$data['CartsItem']['quantity'] = 1;
		}

		return $data;
	}

/**
 * afterAddItemRedirect
 *
 * @param array $item
 * @return vodi
 */
	public function afterAddItemRedirect($item) {
		extract($this->settings);
		$this->Session->setFlash(__d('cart', 'You added %s %s to your cart', $item['quantity'], $item['name']));
		if (is_string($afterAddItemRedirect) || is_array($afterAddItemRedirect)) {
			$this->Controller->redirect($afterAddItemRedirect);
		} elseif ($afterAddItemRedirect === true) {
			$this->Controller->redirect($this->Controller->referer());
		}
	}

/**
 * Handles the buy process of an item via a http get request and url parameters
 *
 * @return mixed false or array
 */
	public function getBuy() {
		if ($this->Controller->request->is('get') && isset($this->Controller->request->params['named']['item'])) {
			$data = array(
				'CartsItem' => array(
					'foreign_key' => $this->Controller->request->params['named']['item'],
					'model' => $this->Controller->modelClass));

			if (isset($this->Controller->request->params['named']['model'])) {
				$data['CartsItem']['model'] = $this->Controller->request->params['named']['model'];
			}

			if (isset($this->Controller->request->params['named']['quantity'])) {
				$data['CartsItem']['quantity'] = $this->Controller->request->params['named']['quantity'];
			}

			return $data;
		}
		return false;
	}

/**
 * Handels the buy process of an item via http post request
 *
 * @return mixed false or array
 */
	public function postBuy() {
		if ($this->Controller->request->is('post')) {
			return $this->Controller->request->data;
		}
		return false;
	}

/**
 * Adds an item to the cart, the session and database if a user is logged in
 *
 * @param array $data
 * @return boolean
 */
	public function addItem($data, $recalculate = true) {
		extract($this->settings);

		$data = $this->_additionalData($data);

		CakeEventManager::dispatch(new CakeEvent('CartManager.beforeAddItem'), $this, array($data));

		$Model = ClassRegistry::init($data['CartsItem']['model']);
		$behaviorMethods = array_keys($Model->Behaviors->methods());

		if ((!method_exists($Model, 'isBuyable') || !method_exists($Model, 'beforeAddToCart')) && 
			!in_array('isBuyable', $behaviorMethods) || !in_array('beforeAddToCart', $behaviorMethods)) {
			throw new InternalErrorException(__('The model %s is not implementing isBuyable() or beforeAddToCart() or is not using the BuyableBehavior!', get_class($Model)));
		}

		if (!$Model->isBuyable($data)) {
			// throw exception?
			return false;
		}

		$data = $Model->beforeAddToCart($data);
		if ($data === false || !is_array($data)) {
			return false;
		}

		if ($this->_isLoggedIn) {
			$data = $this->CartModel->addItem($this->_cartId, $data);
			if ($data === false) {
				return false;
			}
			$data = $data['CartsItem'];
		}

		$result = $this->CartSession->addItem($data);
		if ($recalculate) {
			$this->calculateCart();
		}

		CakeEventManager::dispatch(new CakeEvent('CartManager.afterAddItem'), $this, array($result));

		return $result;
	}

/**
 * Removes an item from the cart session and if a user is logged in from the database
 *
 * @param array $data
 * @return boolean
 */
	public function removeItem($data = null) {
		extract($this->settings);

		CakeEventManager::dispatch(new CakeEvent('CartManager.beforeRemoveItem'), $this, array($result));

		if ($this->_isLoggedIn) {
			$this->CartModel->removeItem($this->_cartId, $data);
		}

		$result = $this->CartSession->removeItem($data);
		$this->calculateCart();

		CakeEventManager::dispatch(new CakeEvent('CartManager.afterRemoveItem'), $this, array($result));
		return $result;
	}

/**
 * Drops all items and re-initializes the cart
 *
 * @param array $data
 * @return void
 */
	public function emptyCart() {
		if ($this->_isLoggedIn) {
			$this->CartModel->emptyCart($this->_cartId);
		}

		if ($this->settings['useCookie'] == true) {
			$this->Cookie->delete($this->settings['cookieName']);
		}

		$result = $this->CartSession->emptyCart();

		$this->initalizeCart();
		return $result;
	}

/**
 * Cart content
 *
 * @return array
 */
	public function content() {
		$this->calculateCart();
		return $this->Session->read($this->settings['sessionKey']);
	}

/**
 * Find if an item already exists in the cart
 *
 * @param mixed $id integer or string uuid
 * @param string $model Model name
 * @return mixed False or key of the array entry in the cart session
 */
	public function contains($id, $model) {
		return $this->CartSession->contains($id, $model);
	}

/**
 * Checks if the cart requires shipping
 *
 * @return boolean
 */
	public function requiresShipping() {
		return (bool) $this->Session->read($this->settings['sessionKey'] . '.Cart.requires_shipping');
	}

/**
 * (Re-)calculates a cart, this will run over all items, coupons and taxes
 * 
 * @todo refactor saveAll?
 * @return array the cart data
 */
	public function calculateCart() {
		$sessionKey = $this->settings['sessionKey'];
		$cartData = $this->CartModel->calculateCart($this->Session->read($sessionKey));

		if ($this->_isLoggedIn) {
			//$this->CartModel->saveAll($cartData);
		}

		$this->Session->write($sessionKey, $cartData);
		return $cartData;
	}

/**
 * Stores the whole cart in a cookie
 *
 * Use this in the case the user is not logged in to make it semi-persistant
 *
 * @return boolean
 */
	public function writeCookie() {
		return $this->Cookie->write($this->settings['cookieName'], $this->content());
	}

/**
 * Used to restore a cart from a cookie
 * 
 * Use this in the case the user was not logged in and left the page for a longer time
 *
 * @return boolean true on success
 */
	public function restoreFromCookie() {
		$result = $this->Cookie->read($this->settings['cookieName']);
		$this->Controller->Session->write($this->settings['sessionKey'], $result);
	}

/**
 * //@todo
 */
	public function updateItems($items) {
		try {
			foreach ($items as $item) {
				$this->addItem(array('CartsItem' => $item), false);
			}
			$this->calculateCart();
		} catch (Exception $e) {
			throw $e;
		}
	}

/**
 * afterFilter callback
 *
 * @return void
 */
	public function afterFilter() {
		if ($this->settings['useCookie']) {
			$this->writeCookie();
		}
	}

}