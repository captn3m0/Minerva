<?php
/**
 * File: Amazon PAS
 * 	Product Advertising Service (http://aws.amazon.com/associates)
 *
 * Version:
 * 	2010.11.26
 *
 * See Also:
 * 	[Amazon PAS](http://aws.amazon.com/associates)
 */


/*%******************************************************************************************%*/
// EXCEPTIONS

/**
 * Exception: PAS_Exception
 * 	Default PAS Exception.
 */
class PAS_Exception extends Exception {}


/*%******************************************************************************************%*/
// MAIN CLASS

/**
 * Class: AmazonPAS
 * 	Container for all Amazon PAS-related methods. Inherits additional methods from CFRuntime.
 */
class AmazonPAS extends CFRuntime
{
	/**
	 * Property: locale
	 * The Amazon locale to use by default.
	 */
	var $locale;


	/*%******************************************************************************************%*/
	// CONSTANTS

	/**
	 * Constant: LOCALE_US
	 * 	Locale code for the United States
	 */
	const LOCALE_US = 'us';

	/**
	 * Constant: LOCALE_UK
	 * 	Locale code for the United Kingdom
	 */
	const LOCALE_UK = 'uk';

	/**
	 * Constant: LOCALE_CANADA
	 * 	Locale code for Canada
	 */
	const LOCALE_CANADA = 'ca';

	/**
	 * Constant: LOCALE_FRANCE
	 * 	Locale code for France
	 */
	const LOCALE_FRANCE = 'fr';

	/**
	 * Constant: LOCALE_GERMANY
	 * 	Locale code for Germany
	 */
	const LOCALE_GERMANY = 'de';

	/**
	 * Constant: LOCALE_JAPAN
	 * 	Locale code for Japan
	 */
	const LOCALE_JAPAN = 'jp';


	/*%******************************************************************************************%*/
	// CONSTRUCTOR

	/**
	 * Method: __construct()
	 * 	The constructor
	 *
	 * Access:
	 * 	public
	 *
	 * Parameters:
	 * 	$key - _string_ (Optional) Your Amazon API Key. If blank, it will look for the <AWS_KEY> constant.
	 * 	$secret_key - _string_ (Optional) Your Amazon API Secret Key. If blank, it will look for the <AWS_SECRET_KEY> constant.
	 * 	$assoc_id - _string_ (Optional) Your Amazon Associates ID. If blank, it will look for the <AWS_ASSOC_ID> constant.
	 *
	 * Returns:
	 * 	_boolean_ false if no valid values are set, otherwise true.
	 */
	public function __construct($key = null, $secret_key = null, $assoc_id = null)
	{
		$this->api_version = '2010-06-01';

		if (!$key && !defined('AWS_KEY'))
		{
			throw new PAS_Exception('No account key was passed into the constructor, nor was it set in the AWS_KEY constant.');
		}

		if (!$secret_key && !defined('AWS_SECRET_KEY'))
		{
			throw new PAS_Exception('No account secret was passed into the constructor, nor was it set in the AWS_SECRET_KEY constant.');
		}

		if (!$assoc_id && !defined('AWS_ASSOC_ID'))
		{
			throw new PAS_Exception('No Amazon Associates ID was passed into the constructor, nor was it set in the AWS_ASSOC_ID constant.');
		}

		return parent::__construct($key, $secret_key, null, $assoc_id);
	}


	/*%******************************************************************************************%*/
	// SET CUSTOM SETTINGS

	/**
	 * Method: set_locale()
	 * 	Override the default locale to use for PAS requests.
	 *
	 * Access:
	 * 	public
	 *
	 * Parameters:
	 * 	$locale - _string_ (Optional) The locale to use. Allows <LOCALE_US>, <LOCALE_UK>, <LOCALE_CANADA>, <LOCALE_FRANCE>, <LOCALE_GERMANY>, <LOCALE_JAPAN>
	 *
	 * Returns:
	 * 	`$this`
	 */
	public function set_locale($locale = null)
	{
		$this->locale = $locale;
		return $this;
	}


	/*%******************************************************************************************%*/
	// CORE FUNCTIONALITY

	/**
	 * Method: authenticate()
	 * 	Construct a URL to request from Amazon, request it, and return a formatted response.
	 *
	 * Access:
	 * 	public
	 *
	 * Parameters:
	 * 	$action - _string_ (Required) Indicates the action to perform.
	 * 	$opt - _array_ (Optional) Associative array of parameters. See the individual methods for allowed keys.
	 * 	$locale - _string_ (Optional) Which Amazon-supported locale do we use? Defaults to United States.
	 *
	 * Returns:
	 * 	<CFResponse> object
	 */
	public function authenticate($action, $opt = null)
	{
		return $this->pas_authenticate($action, $opt);
	}
	
	public function pas_authenticate($action, $opt = null)
	{
		// Was this set with set_locale()?
		if ($this->locale !== null)
		{
			$locale = $this->locale;
		}

		// Fall back to the one set in the config file.
		else
		{
			$locale = (defined('AWS_DEFAULT_LOCALE')) ? AWS_DEFAULT_LOCALE : null;
		}

		// Determine the hostname
		switch ($locale)
		{
			// United Kingdom
			case self::LOCALE_UK:
				$hostname = 'ecs.amazonaws.co.uk';
				break;

			// Canada
			case self::LOCALE_CANADA:
				$hostname = 'ecs.amazonaws.ca';
				break;

			// France
			case self::LOCALE_FRANCE:
				$hostname = 'ecs.amazonaws.fr';
				break;

			// Germany
			case self::LOCALE_GERMANY:
				$hostname = 'ecs.amazonaws.de';
				break;

			// Japan
			case self::LOCALE_JAPAN:
				$hostname = 'ecs.amazonaws.jp';
				break;

			// Default to United States
			default:
				$hostname = 'ecs.amazonaws.com';
				break;
		}

		// Use alternate hostname, if one exists.
		if ($this->hostname)
		{
			$hostname = $this->hostname;
		}
		
		$method_arguments = func_get_args();

		// Use the caching flow to determine if we need to do a round-trip to the server.
		if ($this->use_cache_flow)
		{
			// Generate an identifier specific to this particular set of arguments.
			$cache_id = $this->key . '_' . get_class($this) . '_' . $action . '_' . sha1(serialize($method_arguments));

			// Instantiate the appropriate caching object.
			$this->cache_object = new $this->cache_class($cache_id, $this->cache_location, $this->cache_expires, $this->cache_compress);

			if ($this->delete_cache)
			{
				$this->use_cache_flow = false;
				$this->delete_cache = false;
				return $this->cache_object->delete();
			}

			// Invoke the cache callback function to determine whether to pull data from the cache or make a fresh request.
			$data = $this->cache_object->response_manager(array($this, 'cache_callback'), $method_arguments);

			// Parse the XML body
			$data = $this->parse_callback($data);

			// End!
			return $data;
		}

		$return_curl_handle = false;

		// Manage the key-value pairs that are used in the query.
		$query['AWSAccessKeyId'] = $this->key;
		$query['Operation'] = $action;
		$query['Service'] = 'AWSECommerceService';
		$query['SignatureMethod'] = 'HmacSHA256';
		$query['SignatureVersion'] = 2;
		$query['Timestamp'] = gmdate($this->util->konst($this->util, 'DATE_FORMAT_ISO8601'), time() + $this->adjust_offset);
		$query['Version'] = $this->api_version;

		// Merge in any options that were passed in
		if (is_array($opt))
		{
			$query = array_merge($query, $opt);
		}

		$return_curl_handle = isset($query['returnCurlHandle']) ? $query['returnCurlHandle'] : false;
		unset($query['returnCurlHandle']);

		// Do a case-sensitive, natural order sort on the array keys.
		uksort($query, 'strcmp');

		// Create the string that needs to be hashed.
		$canonical_query_string = $this->util->to_signable_string($query);

		// Set the proper verb.
		$verb = 'GET';

		// Set the proper path
		$path = '/onca/xml';

		// Prepare the string to sign
		$stringToSign = "$verb\n$hostname\n$path\n$canonical_query_string";

		// Hash the AWS secret key and generate a signature for the request.
		$query['Signature'] = $this->util->hex_to_base64(hash_hmac('sha256', $stringToSign, $this->secret_key));

		// Generate the querystring from $query
		$querystring = $this->util->to_query_string($query);

		// Gather information to pass along to other classes.
		$helpers = array(
			'utilities' => $this->utilities_class,
			'request' => $this->request_class,
			'response' => $this->response_class,
		);

		// Compose the request.
		$request_url = $this->use_ssl ? 'https://' : 'http://';
		$request_url .= $hostname;
		$request_url .= $path;
		$request_url .= '?' . $querystring;
		$request = new $this->request_class($request_url, $this->proxy, $helpers);
		$request->set_useragent(self::USERAGENT);

		// If we have a "true" value for returnCurlHandle, do that instead of completing the request.
		if ($return_curl_handle)
		{
			return $request->prep_request();
		}

		// Send!
		$request->send_request();

		// Prepare the response.
		$headers = $request->get_response_header();
		$headers['x-aws-requesturl'] = $request_url;
		$headers['x-aws-stringtosign'] = $stringToSign;

		return new $this->response_class($headers, $this->parse_callback($request->get_response_body()), $request->get_response_code());
	}


	/*%******************************************************************************************%*/
	// BROWSE NODE LOOKUP

	/**
	 * Method: browse_node_lookup()
	 * 	Given a browse node ID, <browse_node_lookup()> returns the specified browse node's name, children, and ancestors. The names and browse node IDs of the children and ancestor browse nodes are also returned. <browse_node_lookup()> enables you to traverse the browse node hierarchy to find a browse node.
	 *
	 * 	If you added your Associates ID to the config.inc.php file, or you passed it into the AmazonPAS() constructor, it will be passed along in this request automatically.
	 *
	 * Access:
	 * 	public
	 *
	 * Parameters:
	 * 	$browse_node_id - _integer_ (Required) A positive integer assigned by Amazon that uniquely identifies a product category.
	 * 	$opt - _array_ (Optional) Associative array of parameters which can have the following keys:
	 *
	 * Keys for the $opt parameter:
	 * 	THIS IS AN INCOMPLETE LIST. For the latest information, check the AWS documentation page (noted below), or run the <help()> method (noted in the examples below).
	 *
	 * 	ContentType - _string_ (Optional) Specifies the format of the content in the response. Generally, ContentType should only be changed for REST requests when the Style parameter is set to an XSLT stylesheet. For example, to transform your Amazon Associates Web Service response into HTML, set ContentType to text/html. Allows 'text/xml' and 'text/html'. Defaults to 'text/xml'.
	 * 	MerchantId - _string_ (Optional) An alphanumeric token distributed by Amazon that uniquely identifies a merchant. Allows 'All', 'Amazon', 'FeaturedBuyBoxMerchant', or a specific Merchant ID. Defaults to 'Amazon'.
	 * 	ResponseGroup - _string_ (Optional) Specifies the types of values to return. You can specify multiple response groups in one request by separating them with commas. Allows 'BrowseNodeInfo' (default), 'NewReleases', 'TopSellers'.
	 * 	returnCurlHandle - _boolean_ (Optional) A private toggle that will return the CURL handle for the request rather than actually completing the request. This is useful for MultiCURL requests.
	 * 	Style - _string_ (Optional) Controls the format of the data returned in Amazon Associates Web Service responses. Set this parameter to "XML," the default, to generate a pure XML response. Set this parameter to the URL of an XSLT stylesheet to have Amazon Associates Web Service transform the XML response. See ContentType.
	 * 	Validate - _boolean_ (Optional) Prevents an operation from executing. Set the Validate parameter to True to test your request without actually executing it. When present, Validate must equal True; the default value is False. If a request is not actually executed (Validate=True), only a subset of the errors for a request may be returned because some errors (for example, no_exact_matches) are only generated during the execution of a request. Defaults to FALSE.
	 * 	XMLEscaping - _string_ (Optional) Specifies whether responses are XML-encoded in a single pass or a double pass. By default, XMLEscaping is Single, and Amazon Associates Web Service responses are encoded only once in XML. For example, if the response data includes an ampersand character (&), the character is returned in its regular XML encoding (&). If XMLEscaping is Double, the same ampersand character is XML-encoded twice (&amp;). The Double value for XMLEscaping is useful in some clients, such as PHP, that do not decode text within XML elements. Defaults to 'Single'.
	 *
	 * Returns:
	 * 	<CFResponse> object
	 *
	 * See Also:
	 * 	[BrowseNodeLookup Operation](http://docs.amazonwebservices.com/AWSECommerceService/latest/DG/BrowseNodeLookup.html)
	 */
	public function browse_node_lookup($browse_node_id, $opt = null)
	{
		if (!$opt) $opt = array();
		$opt['BrowseNodeId'] = $browse_node_id;

		if (isset($this->assoc_id))
		{
			$opt['AssociateTag'] = $this->assoc_id;
		}

		return $this->pas_authenticate('BrowseNodeLookup', $opt);
	}


	/*%******************************************************************************************%*/
	// CART METHODS

	/**
	 * Method: cart_add()
	 * 	Enables you to add items to an existing remote shopping cart. <cart_add()> can only be used to place a new item in a shopping cart. It cannot be used to increase the quantity of an item already in the cart. If you would like to increase the quantity of an item that is already in the cart, you must use the <cart_modify()> operation.
	 *
	 * 	You add an item to a cart by specifying the item's OfferListingId, or ASIN and ListItemId. Once in a cart, an item can only be identified by its CartItemId. That is, an item in a cart cannot be accessed by its ASIN or OfferListingId. CartItemId is returned by <cart_create()>, <cart_get()>, and <cart_add()>.
	 *
	 * 	To add items to a cart, you must specify the cart using the CartId and HMAC values, which are returned by the <cart_create()> operation.
	 *
	 * 	If you added your Associates ID to the config.inc.php file, or you passed it into the AmazonPAS() constructor, it will be passed along in this request automatically.
	 *
	 * Access:
	 * 	public
	 *
	 * Parameters:
	 * 	$cart_id - _string_ (Required) Alphanumeric token returned by <cart_create()> that identifies a cart.
	 * 	$hmac - _string_ (Required) Encrypted alphanumeric token returned by <cart_create()> that authorizes access to a cart.
	 * 	$offer_listing_id - _string_|_array_ (Required) Either a string containing the Offer ID to add, or an associative array where the Offer ID is the key and the quantity is the value. An offer listing ID is an alphanumeric token that uniquely identifies an item. Use the OfferListingId instead of an item's ASIN to add the item to the cart.
	 * 	$opt - _array_ (Optional) Associative array of parameters which can have the following keys:
	 *
	 * Keys for the $opt parameter:
	 * 	THIS IS AN INCOMPLETE LIST. For the latest information, check the AWS documentation page (noted below), or run the <help()> method (noted in the examples below).
	 *
	 * 	ContentType - _string_ (Optional) Specifies the format of the content in the response. Generally, ContentType should only be changed for REST requests when the Style parameter is set to an XSLT stylesheet. For example, to transform your Amazon Associates Web Service response into HTML, set ContentType to text/html. Allows 'text/xml' and 'text/html'. Defaults to 'text/xml'.
	 * 	MergeCart - _boolean_ (Optional) A boolean value that when True specifies that the items in a customer's remote shopping cart are added to the customer's Amazon retail shopping cart. This occurs when the customer elects to purchase the items in their remote shopping cart. When the value is False the remote shopping cart contents are not added to the retail shopping cart. Instead, the customer is sent directly to the Order Pipeline when they elect to purchase the items in their cart. This parameter is valid only in the US locale. In all other locales, the parameter is invalid but the request behaves as though the value were set to True.
	 * 	MerchantId - _string_ (Optional) An alphanumeric token distributed by Amazon that uniquely identifies a merchant. Allows 'All', 'Amazon', 'FeaturedBuyBoxMerchant', or a specific Merchant ID. Defaults to 'Amazon'.
	 * 	ResponseGroup - _string_ (Optional) Specifies the types of values to return. You can specify multiple response groups in one request by separating them with commas.
	 * 	returnCurlHandle - _boolean_ (Optional) A private toggle that will return the CURL handle for the request rather than actually completing the request. This is useful for MultiCURL requests.
	 * 	Style - _string_ (Optional) Controls the format of the data returned in Amazon Associates Web Service responses. Set this parameter to "XML," the default, to generate a pure XML response. Set this parameter to the URL of an XSLT stylesheet to have Amazon Associates Web Service transform the XML response. See ContentType.
	 * 	Validate - _boolean_ (Optional) Prevents an operation from executing. Set the Validate parameter to True to test your request without actually executing it. When present, Validate must equal True; the default value is False. If a request is not actually executed (Validate=True), only a subset of the errors for a request may be returned because some errors (for example, no_exact_matches) are only generated during the execution of a request. Defaults to FALSE.
	 * 	XMLEscaping - _string_ (Optional) Specifies whether responses are XML-encoded in a single pass or a double pass. By default, XMLEscaping is Single, and Amazon Associates Web Service responses are encoded only once in XML. For example, if the response data includes an ampersand character (&), the character is returned in its regular XML encoding (&). If XMLEscaping is Double, the same ampersand character is XML-encoded twice (&amp;). The Double value for XMLEscaping is useful in some clients, such as PHP, that do not decode text within XML elements. Defaults to 'Single'.
 	 *
	 * Returns:
	 * 	<CFResponse> object
	 *
	 * See Also:
	 * 	[CartAdd Operation](http://docs.amazonwebservices.com/AWSECommerceService/latest/DG/CartAdd.html)
	 */
	public function cart_add($cart_id, $hmac, $offer_listing_id, $opt = null)
	{
		if (!$opt) $opt = array();
		$opt['CartId'] = $cart_id;
		$opt['HMAC'] = $hmac;

		if (is_array($offer_listing_id))
		{
			$count = 1;
			foreach ($offer_listing_id as $offer => $quantity)
			{
				$opt['Item.' . $count . '.OfferListingId'] = $offer;
				$opt['Item.' . $count . '.Quantity'] = $quantity;

				$count++;
			}
		}
		else
		{
			$opt['Item.1.OfferListingId'] = $offer_listing_id;
			$opt['Item.1.Quantity'] = 1;
		}

		if (isset($this->assoc_id))
		{
			$opt['AssociateTag'] = $this->assoc_id;
		}

		return $this->pas_authenticate('CartAdd', $opt);
	}

	/**
	 * Method: cart_clear()
	 * 	Enables you to remove all of the items in a remote shopping cart, including SavedForLater items. To remove only some of the items in a cart or to reduce the quantity of one or more items, use <cart_modify()>.
	 *
	 * 	To delete all of the items from a remote shopping cart, you must specify the cart using the CartId and HMAC values, which are returned by the <cart_create()> operation. A value similar to the HMAC, URLEncodedHMAC, is also returned. This value is the URL encoded version of the HMAC. This encoding is necessary because some characters, such as + and /, cannot be included in a URL. Rather than encoding the HMAC yourself, use the URLEncodedHMAC value for the HMAC parameter.
	 *
	 * 	<cart_clear()> does not work after the customer has used the PurchaseURL to either purchase the items or merge them with the items in their Amazon cart. Carts exist even though they have been emptied. The lifespan of a cart is 7 days since the last time it was acted upon. For example, if a cart created 6 days ago is modified, the cart lifespan is reset to 7 days.
	 *
	 * 	If you added your Associates ID to the config.inc.php file, or you passed it into the AmazonPAS() constructor, it will be passed along in this request automatically.
	 *
	 * Access:
	 * 	public
	 *
	 * Parameters:
	 * 	$cart_id - _string_ (Required) Alphanumeric token returned by <cart_create()> that identifies a cart.
	 * 	$hmac - _string_ (Required) Encrypted alphanumeric token returned by <cart_create()> that authorizes access to a cart.
	 * 	$opt - _array_ (Optional) Associative array of parameters which can have the following keys:
	 *
	 * Keys for the $opt parameter:
	 * 	THIS IS AN INCOMPLETE LIST. For the latest information, check the AWS documentation page (noted below), or run the <help()> method (noted in the examples below).
	 *
	 * 	ContentType - _string_ (Optional) Specifies the format of the content in the response. Generally, ContentType should only be changed for REST requests when the Style parameter is set to an XSLT stylesheet. For example, to transform your Amazon Associates Web Service response into HTML, set ContentType to text/html. Allows 'text/xml' and 'text/html'. Defaults to 'text/xml'.
	 * 	MergeCart - _boolean_ (Optional) A boolean value that when True specifies that the items in a customer's remote shopping cart are added to the customer's Amazon retail shopping cart. This occurs when the customer elects to purchase the items in their remote shopping cart. When the value is False the remote shopping cart contents are not added to the retail shopping cart. Instead, the customer is sent directly to the Order Pipeline when they elect to purchase the items in their cart. This parameter is valid only in the US locale. In all other locales, the parameter is invalid but the request behaves as though the value were set to True.
	 * 	MerchantId - _string_ (Optional) An alphanumeric token distributed by Amazon that uniquely identifies a merchant. Allows 'All', 'Amazon', 'FeaturedBuyBoxMerchant', or a specific Merchant ID. Defaults to 'Amazon'.
	 * 	ResponseGroup - _string_ (Optional) Specifies the types of values to return. You can specify multiple response groups in one request by separating them with commas.
	 * 	returnCurlHandle - _boolean_ (Optional) A private toggle that will return the CURL handle for the request rather than actually completing the request. This is useful for MultiCURL requests.
	 * 	Style - _string_ (Optional) Controls the format of the data returned in Amazon Associates Web Service responses. Set this parameter to "XML," the default, to generate a pure XML response. Set this parameter to the URL of an XSLT stylesheet to have Amazon Associates Web Service transform the XML response. See ContentType.
	 * 	Validate - _boolean_ (Optional) Prevents an operation from executing. Set the Validate parameter to True to test your request without actually executing it. When present, Validate must equal True; the default value is False. If a request is not actually executed (Validate=True), only a subset of the errors for a request may be returned because some errors (for example, no_exact_matches) are only generated during the execution of a request. Defaults to FALSE.
	 * 	XMLEscaping - _string_ (Optional) Specifies whether responses are XML-encoded in a single pass or a double pass. By default, XMLEscaping is Single, and Amazon Associates Web Service responses are encoded only once in XML. For example, if the response data includes an ampersand character (&), the character is returned in its regular XML encoding (&). If XMLEscaping is Double, the same ampersand character is XML-encoded twice (&amp;). The Double value for XMLEscaping is useful in some clients, such as PHP, that do not decode text within XML elements. Defaults to 'Single'.
	 *
	 * Returns:
	 * 	<CFResponse> object
	 *
	 * See Also:
	 * 	[CartClear](http://docs.amazonwebservices.com/AWSECommerceService/latest/DG/CartClear.html)
	 */
	public function cart_clear($cart_id, $hmac, $opt = null)
	{
		if (!$opt) $opt = array();
		$opt['CartId'] = $cart_id;
		$opt['HMAC'] = $hmac;

		if (isset($this->assoc_id))
		{
			$opt['AssociateTag'] = $this->assoc_id;
		}

		return $this->pas_authenticate('CartClear', $opt);
	}

	/**
	 * Method: cart_create()
	 * 	Enables you to create a remote shopping cart. A shopping cart is the metaphor used by most e-commerce solutions. It is a temporary data storage structure that resides on Amazon servers. The structure contains the items a customer wants to buy. In Amazon Associates Web Service, the shopping cart is considered remote because it is hosted by Amazon servers. In this way, the cart is remote to the vendor's web site where the customer views and selects the items they want to purchase.
	 *
	 * 	Once you add an item to a cart by specifying the item's ListItemId and ASIN, or OfferListing ID, the item is assigned a CartItemId and accessible only by that value. That is, in subsequent requests, an item in a cart cannot be accessed by its ListItemId and ASIN, or OfferListingId.
	 *
	 * 	Because the contents of a cart can change for different reasons, such as item availability, you should not keep a copy of a cart locally. Instead, use the other cart operations to modify the cart contents. For example, to retrieve contents of the cart, which are represented by CartItemIds, use <cart_get()>.
	 *
	 * 	Available products are added as cart items. Unavailable items, for example, items out of stock, discontinued, or future releases, are added as SaveForLaterItems. No error is generated. The Amazon database changes regularly. You may find a product with an offer listing ID but by the time the item is added to the cart the product is no longer available. The checkout page in the Order Pipeline clearly lists items that are available and those that are SaveForLaterItems.
	 *
	 * 	It is impossible to create an empty shopping cart. You have to add at least one item to a shopping cart using a single <cart_create()> request. You can add specific quantities (up to 999) of each item. <cart_create()> can be used only once in the life cycle of a cart. To modify the contents of the cart, use one of the other cart operations.
	 *
	 * 	Carts cannot be deleted. They expire automatically after being unused for 7 days. The lifespan of a cart restarts, however, every time a cart is modified. In this way, a cart can last for more than 7 days. If, for example, on day 6, the customer modifies a cart, the 7 day countdown starts over.
	 *
	 * 	If you added your Associates ID to the config.inc.php file, or you passed it into the AmazonPAS() constructor, it will be passed along in this request automatically.
	 *
	 * Access:
	 * 	public
	 *
	 * Parameters:
	 * 	$offer_listing_id - _string|array_ (Required) Either a string containing the Offer ID to add, or an associative array where the Offer ID is the key and the quantity is the value. An offer listing ID is an alphanumeric token that uniquely identifies an item. Use the OfferListingId instead of an item's ASIN to add the item to the cart.
	 * 	$opt - _array_ (Optional) Associative array of parameters which can have the following keys:
	 *
	 * Keys for the $opt parameter:
	 * 	THIS IS AN INCOMPLETE LIST. For the latest information, check the AWS documentation page (noted below), or run the <help()> method (noted in the examples below).
	 *
	 * 	ContentType - _string_ (Optional) Specifies the format of the content in the response. Generally, ContentType should only be changed for REST requests when the Style parameter is set to an XSLT stylesheet. For example, to transform your Amazon Associates Web Service response into HTML, set ContentType to text/html. Allows 'text/xml' and 'text/html'. Defaults to 'text/xml'.
	 * 	MergeCart - _boolean_ (Optional) A boolean value that when True specifies that the items in a customer's remote shopping cart are added to the customer's Amazon retail shopping cart. This occurs when the customer elects to purchase the items in their remote shopping cart. When the value is False the remote shopping cart contents are not added to the retail shopping cart. Instead, the customer is sent directly to the Order Pipeline when they elect to purchase the items in their cart. This parameter is valid only in the US locale. In all other locales, the parameter is invalid but the request behaves as though the value were set to True.
	 * 	MerchantId - _string_ (Optional) An alphanumeric token distributed by Amazon that uniquely identifies a merchant. Allows 'All', 'Amazon', 'FeaturedBuyBoxMerchant', or a specific Merchant ID. Defaults to 'Amazon'.
	 * 	ResponseGroup - _string_ (Optional) Specifies the types of values to return. You can specify multiple response groups in one request by separating them with commas.
	 * 	returnCurlHandle - _boolean_ (Optional) A private toggle that will return the CURL handle for the request rather than actually completing the request. This is useful for MultiCURL requests.
	 * 	Style - _string_ (Optional) Controls the format of the data returned in Amazon Associates Web Service responses. Set this parameter to "XML," the default, to generate a pure XML response. Set this parameter to the URL of an XSLT stylesheet to have Amazon Associates Web Service transform the XML response. See ContentType.
	 * 	Validate - _boolean_ (Optional) Prevents an operation from executing. Set the Validate parameter to True to test your request without actually executing it. When present, Validate must equal True; the default value is False. If a request is not actually executed (Validate=True), only a subset of the errors for a request may be returned because some errors (for example, no_exact_matches) are only generated during the execution of a request. Defaults to FALSE.
	 * 	XMLEscaping - _string_ (Optional) Specifies whether responses are XML-encoded in a single pass or a double pass. By default, XMLEscaping is Single, and Amazon Associates Web Service responses are encoded only once in XML. For example, if the response data includes an ampersand character (&), the character is returned in its regular XML encoding (&). If XMLEscaping is Double, the same ampersand character is XML-encoded twice (&amp;). The Double value for XMLEscaping is useful in some clients, such as PHP, that do not decode text within XML elements. Defaults to 'Single'.
	 *
	 * Returns:
	 * 	<CFResponse> object
	 *
	 * See Also:
	 * 	[CartCreate](http://docs.amazonwebservices.com/AWSECommerceService/latest/DG/CartCreate.html)
	 */
	public function cart_create($offer_listing_id, $opt = null)
	{
		if (!$opt) $opt = array();

		if (is_array($offer_listing_id))
		{
			$count = 1;
			foreach ($offer_listing_id as $offer => $quantity)
			{
				$opt['Item.' . $count . '.OfferListingId'] = $offer;
				$opt['Item.' . $count . '.Quantity'] = $quantity;

				$count++;
			}
		}
		else
		{
			$opt['Item.1.OfferListingId'] = $offer_listing_id;
			$opt['Item.1.Quantity'] = 1;
		}

		if (isset($this->assoc_id))
		{
			$opt['AssociateTag'] = $this->assoc_id;
		}

		return $this->pas_authenticate('CartCreate', $opt);
	}

	/**
	 * Method: cart_get()
	 * 	Enables you to retrieve the IDs, quantities, and prices of all of the items, including SavedForLater items in a remote shopping cart.
	 *
	 * 	Because the contents of a cart can change for different reasons, such as availability, you should not keep a copy of a cart locally. Instead, use <cart_get()> to retrieve the items in a remote shopping cart. To retrieve the items in a cart, you must specify the cart using the CartId and HMAC values, which are returned in the <cart_create()> operation. A value similar to HMAC, URLEncodedHMAC, is also returned.
	 *
	 * 	This value is the URL encoded version of the HMAC. This encoding is necessary because some characters, such as + and /, cannot be included in a URL. Rather than encoding the HMAC yourself, use the URLEncodedHMAC value for the HMAC parameter.
	 *
	 * 	<cart_get()> does not work after the customer has used the PurchaseURL to either purchase the items or merge them with the items in their Amazon cart.
	 *
	 * Access:
	 * 	public
	 *
	 * Parameters:
	 * 	$cart_id - _string_ (Required) Alphanumeric token returned by <cart_create()> that identifies a cart.
	 * 	$hmac - _string_ (Required) Encrypted alphanumeric token returned by <cart_create()> that authorizes access to a cart.
	 * 	$opt - _array_ (Optional) Associative array of parameters which can have the following keys:
	 *
	 * Keys for the $opt parameter:
	 * 	THIS IS AN INCOMPLETE LIST. For the latest information, check the AWS documentation page (noted below), or run the <help()> method (noted in the examples below).
	 *
	 * 	CartItemId - _string_ (Optional) Alphanumeric token that uniquely identifies an item in a cart. Once an item, specified by an ASIN or OfferListingId, has been added to a cart, you must use the CartItemId to refer to it. The other identifiers will not work.
	 * 	ContentType - _string_ (Optional) Specifies the format of the content in the response. Generally, ContentType should only be changed for REST requests when the Style parameter is set to an XSLT stylesheet. For example, to transform your Amazon Associates Web Service response into HTML, set ContentType to text/html. Allows 'text/xml' and 'text/html'. Defaults to 'text/xml'.
	 * 	MergeCart - _boolean_ (Optional) A boolean value that when True specifies that the items in a customer's remote shopping cart are added to the customer's Amazon retail shopping cart. This occurs when the customer elects to purchase the items in their remote shopping cart. When the value is False the remote shopping cart contents are not added to the retail shopping cart. Instead, the customer is sent directly to the Order Pipeline when they elect to purchase the items in their cart. This parameter is valid only in the US locale. In all other locales, the parameter is invalid but the request behaves as though the value were set to True.
	 * 	MerchantId - _string_ (Optional) An alphanumeric token distributed by Amazon that uniquely identifies a merchant. Allows 'All', 'Amazon', 'FeaturedBuyBoxMerchant', or a specific Merchant ID. Defaults to 'Amazon'.
	 * 	ResponseGroup - _string_ (Optional) Specifies the types of values to return. You can specify multiple response groups in one request by separating them with commas.
	 * 	returnCurlHandle - _boolean_ (Optional) A private toggle that will return the CURL handle for the request rather than actually completing the request. This is useful for MultiCURL requests.
	 * 	Style - _string_ (Optional) Controls the format of the data returned in Amazon Associates Web Service responses. Set this parameter to "XML," the default, to generate a pure XML response. Set this parameter to the URL of an XSLT stylesheet to have Amazon Associates Web Service transform the XML response. See ContentType.
	 * 	Validate - _boolean_ (Optional) Prevents an operation from executing. Set the Validate parameter to True to test your request without actually executing it. When present, Validate must equal True; the default value is False. If a request is not actually executed (Validate=True), only a subset of the errors for a request may be returned because some errors (for example, no_exact_matches) are only generated during the execution of a request. Defaults to FALSE.
	 * 	XMLEscaping - _string_ (Optional) Specifies whether responses are XML-encoded in a single pass or a double pass. By default, XMLEscaping is Single, and Amazon Associates Web Service responses are encoded only once in XML. For example, if the response data includes an ampersand character (&), the character is returned in its regular XML encoding (&). If XMLEscaping is Double, the same ampersand character is XML-encoded twice (&amp;). The Double value for XMLEscaping is useful in some clients, such as PHP, that do not decode text within XML elements. Defaults to 'Single'.
	 *
	 * Returns:
	 * 	<CFResponse> object
	 *
	 * See Also:
	 * 	[CartGet Operation](http://docs.amazonwebservices.com/AWSECommerceService/latest/DG/CartGet.html)
	 */
	public function cart_get($cart_id, $hmac, $opt = null)
	{
		if (!$opt) $opt = array();
		$opt['CartId'] = $cart_id;
		$opt['HMAC'] = $hmac;

		if (isset($this->assoc_id))
		{
			$opt['AssociateTag'] = $this->assoc_id;
		}

		return $this->pas_authenticate('CartGet', $opt);
	}

	/**
	 * Method: cart_modify()
	 * 	Enables you to change the quantity of items that are already in a remote shopping cart, move items from the active area of a cart to the SaveForLater area or the reverse, and change the MergeCart setting.
	 *
	 * 	To modify the number of items in a cart, you must specify the cart using the CartId and HMAC values that are returned in the <cart_create()> operation. A value similar to HMAC, URLEncodedHMAC, is also returned. This value is the URL encoded version of the HMAC. This encoding is necessary because some characters, such as + and /, cannot be included in a URL. Rather than encoding the HMAC yourself, use the URLEncodedHMAC value for the HMAC parameter.
	 *
	 * 	You can use <cart_modify()> to modify the number of items in a remote shopping cart by setting the value of the Quantity parameter appropriately. You can eliminate an item from a cart by setting the value of the Quantity parameter to zero. Or, you can double the number of a particular item in the cart by doubling its Quantity. You cannot, however, use <cart_modify()> to add new items to a cart.
	 *
	 * Access:
	 * 	public
	 *
	 * Parameters:
	 * 	$cart_id - _string_ (Required) Alphanumeric token returned by <cart_create()> that identifies a cart.
	 * 	$hmac - _string_ (Required) Encrypted alphanumeric token returned by <cart_create()> that authorizes access to a cart.
	 * 	$cart_item_id - _array_ (Required) Associative array that specifies an item to be modified in the cart where N is a positive integer between 1 and 10, inclusive. Up to ten items can be modified at a time. CartItemId is neither an ASIN nor an OfferListingId. It is, instead, an alphanumeric token returned by <cart_create()> and <cart_add()>. This parameter is used in conjunction with Item.N.Quantity to modify the number of items in a cart. Also, instead of adjusting the quantity, you can set 'SaveForLater' or 'MoveToCart' as actions instead.
	 * 	$opt - _array_ (Optional) Associative array of parameters which can have the following keys:
	 *
	 * Keys for the $opt parameter:
	 * 	THIS IS AN INCOMPLETE LIST. For the latest information, check the AWS documentation page (noted below), or run the <help()> method (noted in the examples below).
	 *
	 * 	Action - _string_ (Optional) Change cart items to move items to the Saved-For-Later area, or change Saved-For- Later (SaveForLater) items to the active cart area (MoveToCart).
	 * 	ContentType - _string_ (Optional) Specifies the format of the content in the response. Generally, ContentType should only be changed for REST requests when the Style parameter is set to an XSLT stylesheet. For example, to transform your Amazon Associates Web Service response into HTML, set ContentType to text/html. Allows 'text/xml' and 'text/html'. Defaults to 'text/xml'.
	 * 	ListItemId - _string_ (Optional) The ListItemId parameter is returned by the ListItems response group. The parameter identifies an item on a list, such as a wishlist. To add this item to a cart, you must include in the <cart_create()> request the item's ASIN and ListItemId. The ListItemId includes the name and address of the list owner, which the ASIN alone does not.
	 * 	MergeCart - _boolean_ (Optional) A boolean value that when True specifies that the items in a customer's remote shopping cart are added to the customer's Amazon retail shopping cart. This occurs when the customer elects to purchase the items in their remote shopping cart. When the value is False the remote shopping cart contents are not added to the retail shopping cart. Instead, the customer is sent directly to the Order Pipeline when they elect to purchase the items in their cart. This parameter is valid only in the US locale. In all other locales, the parameter is invalid but the request behaves as though the value were set to True.
	 * 	MerchantId - _string_ (Optional) An alphanumeric token distributed by Amazon that uniquely identifies a merchant. Allows 'All', 'Amazon', 'FeaturedBuyBoxMerchant', or a specific Merchant ID. Defaults to 'Amazon'.
	 * 	ResponseGroup - _string_ (Optional) Specifies the types of values to return. You can specify multiple response groups in one request by separating them with commas.
	 * 	returnCurlHandle - _boolean_ (Optional) A private toggle that will return the CURL handle for the request rather than actually completing the request. This is useful for MultiCURL requests.
	 * 	Style - _string_ (Optional) Controls the format of the data returned in Amazon Associates Web Service responses. Set this parameter to "XML," the default, to generate a pure XML response. Set this parameter to the URL of an XSLT stylesheet to have Amazon Associates Web Service transform the XML response. See ContentType.
	 * 	Validate - _boolean_ (Optional) Prevents an operation from executing. Set the Validate parameter to True to test your request without actually executing it. When present, Validate must equal True; the default value is False. If a request is not actually executed (Validate=True), only a subset of the errors for a request may be returned because some errors (for example, no_exact_matches) are only generated during the execution of a request. Defaults to FALSE.
	 * 	XMLEscaping - _string_ (Optional) Specifies whether responses are XML-encoded in a single pass or a double pass. By default, XMLEscaping is Single, and Amazon Associates Web Service responses are encoded only once in XML. For example, if the response data includes an ampersand character (&), the character is returned in its regular XML encoding (&). If XMLEscaping is Double, the same ampersand character is XML-encoded twice (&amp;). The Double value for XMLEscaping is useful in some clients, such as PHP, that do not decode text within XML elements. Defaults to 'Single'.
	 *
	 * Returns:
	 * 	<CFResponse> object
	 *
	 * See Also:
	 * 	[CartModify Operation](http://docs.amazonwebservices.com/AWSECommerceService/latest/DG/CartModify.html)
	 */
	public function cart_modify($cart_id, $hmac, $cart_item_id, $opt = null)
	{
		if (!$opt) $opt = array();
		$opt['CartId'] = $cart_id;
		$opt['HMAC'] = $hmac;

		if (is_array($cart_item_id))
		{
			$count = 1;
			foreach ($cart_item_id as $offer => $quantity)
			{
				$action = is_numeric($quantity) ? 'Quantity' : 'Action';

				$opt['Item.' . $count . '.CartItemId'] 	= $offer;
				$opt['Item.' . $count . '.' . $action] 		= $quantity;

				$count++;
			}
		}
		else
		{
			throw new PAS_Exception('$cart_item_id MUST be an array. See the ' . self::NAME . ' documentation for more details.');
		}

		if (isset($this->assoc_id))
		{
			$opt['AssociateTag'] = $this->assoc_id;
		}

		return $this->pas_authenticate('CartModify', $opt);
	}


	/*%******************************************************************************************%*/
	// ITEM METHODS

	/**
	 * Method: item_lookup()
	 * 	Given an Item identifier, the ItemLookup operation returns some or all of the item attributes, depending on the response group specified in the request. By default, <item_lookup()> returns an item’s ASIN, DetailPageURL, Manufacturer, ProductGroup, and Title of the item.
	 *
	 * 	If you added your Associates ID to the config.inc.php file, or you passed it into the AmazonPAS() constructor, it will be passed along in this request automatically.
	 *
	 * Access:
	 * 	public
	 *
	 * Parameters:
	 * 	$item_id - _string_ (Required) A positive integer that unique identifies an item. The meaning of the number is specified by IdType. That is, if IdType is ASIN, the ItemId value is an ASIN. If ItemId is an ASIN, a search index cannot be specified in the request.
	 * 	$opt - _array_ (Optional) Associative array of parameters which can have the following keys:
	 *
	 * Keys for the $opt parameter:
	 * 	THIS IS AN INCOMPLETE LIST. For the latest information, check the AWS documentation page (noted below), or run the <help()> method (noted in the examples below).
	 *
	 * 	Condition - _string_ (Optional) Specifies an item's condition. If Condition is set to "All," a separate set of responses is returned for each valid value of Condition. The default value is "New" (not "All"). So, if your request does not return results, consider setting the value to "All." When the value is "New," the ItemSearch Availability parameter cannot be set to "Available." Amazon only sells items that are "New." Allows 'New', 'Used', 'Collectible', 'Refurbished', and 'All'. Defaults to 'New'.
	 * 	ContentType - _string_ (Optional) Specifies the format of the content in the response. Generally, ContentType should only be changed for REST requests when the Style parameter is set to an XSLT stylesheet. For example, to transform your Amazon Associates Web Service response into HTML, set ContentType to text/html. Allows 'text/xml' and 'text/html'. Defaults to 'text/xml'.
	 * 	IdType - _string_ (Optional) Type of item identifier used to look up an item. All IdTypes except ASINx require a SearchIndex to be specified. SKU requires a MerchantId to be specified also. Allows 'ASIN', 'SKU', 'UPC', 'EAN', 'ISBN' (US only, when search index is Books), and 'JAN'. UPC is not valid in the Canadian locale. Defaults to 'ASIN'.
	 * 	MerchantId - _string_ (Optional) An alphanumeric token distributed by Amazon that uniquely identifies a merchant. Allows 'All', 'Amazon', 'FeaturedBuyBoxMerchant', or a specific Merchant ID. Defaults to 'Amazon'.
	 * 	OfferPage - _string_ (Optional) Page of offers returned. There are 10 offers per page. To examine offers 11 trough 20, for example, set OfferPage to 2. Allows 1 through 100.
	 * 	RelatedItemsPage - _integer_ (Optional) This optional parameter is only valid when the RelatedItems response group is used. Each ItemLookup request can return, at most, ten related items. The RelatedItemsPage value specifies the set of ten related items to return. A value of 2, for example, returns the second set of ten related items.
	 * 	RelationshipType - _string_ (Optional) This parameter is required when the RelatedItems response group is used. The type of related item returned is specified by the RelationshipType parameter. Sample values include Episode, Season, and Tracks. For a complete list of types, go to the documentation for "Relationship Types". Required when 'RelatedItems' response group is used.
	 * 	ResponseGroup - _string_ (Optional) Specifies the types of values to return. You can specify multiple response groups in one request by separating them with commas. Check the documentation for all allowed values.
	 * 	returnCurlHandle - _boolean_ (Optional) A private toggle that will return the CURL handle for the request rather than actually completing the request. This is useful for MultiCURL requests.
	 * 	ReviewPage - _integer_ (Optional) Page of reviews returned. There are 5 reviews per page. To examine reviews 6 through 10, for example, set ReviewPage to 2. Allows 1 through 20.
	 * 	ReviewSort - _string_ (Optional) Specifies the order in which Reviews are sorted in the return. Allows '-HelpfulVotes', 'HelpfulVotes', '-OverallRating', 'OverallRating', 'SubmissionDate' and '-SubmissionDate'. Defaults to '-SubmissionDate'.
	 * 	SearchIndex - _string_ (Optional) The product category to search. Constraint: If ItemIds an ASIN, a search index cannot be specified in the request. Required for for non-ASIN ItemIds. Allows any valid search index. See the "Search Indices" documentation page for more details.
	 * 	Style - _string_ (Optional) Controls the format of the data returned in Amazon Associates Web Service responses. Set this parameter to "XML," the default, to generate a pure XML response. Set this parameter to the URL of an XSLT stylesheet to have Amazon Associates Web Service transform the XML response. See ContentType.
	 * 	TagPage - _integer_ (Optional) Specifies the page of results to return. There are ten results on a page. Allows 1 through 400.
	 * 	TagsPerPage - _integer_ (Optional) The number of tags to return that are associated with a specified item.
	 * 	TagSort - _string_ (Optional) Specifies the sorting order for the results. Allows 'FirstUsed', '-FirstUsed', 'LastUsed', '-LastUsed', 'Name', '-Name', 'Usages', and '-Usages'. Defaults to '-Usages'.
	 * 	Validate - _boolean_ (Optional) Prevents an operation from executing. Set the Validate parameter to True to test your request without actually executing it. When present, Validate must equal True; the default value is False. If a request is not actually executed (Validate=True), only a subset of the errors for a request may be returned because some errors (for example, no_exact_matches) are only generated during the execution of a request. Defaults to FALSE.
	 * 	VariationPage - _string_ (Optional) Page number of variations returned by ItemLookup. By default, ItemLookup returns all variations. Use VariationPage to return a subsection of the response. There are 10 variations per page. To examine offers 11 trough 20, for example, set VariationPage to 2. Allows 1 through 150.
	 * 	XMLEscaping - _string_ (Optional) Specifies whether responses are XML-encoded in a single pass or a double pass. By default, XMLEscaping is Single, and Amazon Associates Web Service responses are encoded only once in XML. For example, if the response data includes an ampersand character (&), the character is returned in its regular XML encoding (&). If XMLEscaping is Double, the same ampersand character is XML-encoded twice (&amp;). The Double value for XMLEscaping is useful in some clients, such as PHP, that do not decode text within XML elements. Defaults to 'Single'.
	 *
	 * Returns:
	 * 	<CFResponse> object
	 *
	 * See Also:
	 * 	[ItemLookup Operation](http://docs.amazonwebservices.com/AWSECommerceService/latest/DG/ItemLookup.html)
	 */
	public function item_lookup($item_id, $opt = null)
	{
		if (!$opt) $opt = array();
		$opt['ItemId'] = $item_id;

		if (isset($this->assoc_id))
		{
			$opt['AssociateTag'] = $this->assoc_id;
		}

		return $this->pas_authenticate('ItemLookup', $opt);
	}

	/**
	 * Method: item_search()
	 * 	The <item_search()> operation returns items that satisfy the search criteria, including one or more search indices. <item_search()> is the operation that is used most often in requests. In general, when trying to find an item for sale, you use this operation.
	 *
	 * 	If you added your Associates ID to the config.inc.php file, or you passed it into the AmazonPAS() constructor, it will be passed along in this request automatically.
	 *
	 * Access:
	 * 	public
	 *
	 * Parameters:
	 * 	$keywords - _string_ (Required) A word or phrase associated with an item. The word or phrase can be in various product fields, including product title, author, artist, description, manufacturer, and so forth. When, for example, the search index equals "MusicTracks", the Keywords parameter enables you to search by song title.
	 * 	$opt - _array_ (Optional) Associative array of parameters which can have the following keys:
	 *
	 * Keys for the $opt parameter:
	 * 	THIS IS AN INCOMPLETE LIST. For the latest information, check the AWS documentation page (noted below), or run the <help()> method (noted in the examples below).
	 *
	 * 	Actor - _string_ (Optional) Name of an actor associated with the item. You can enter all or part of the name.
	 * 	Artist - _string_ (Optional) 	Name of an artist associated with the item. You can enter all or part of the name.
	 * 	AudienceRating - _string_ (Optional) Movie ratings based on MPAA ratings or age, depending upon the locale. You may specify one or more values in a comma-separated list.
	 * 	Author - _string_ (Optional) Name of an author associated with the item. You can enter all or part of the name.
	 * 	Availability - _string_ (Optional) Enables ItemSearch to return only those items that are available. This parameter must be used in combination with a merchant ID and Condition. When Availability is set to "Available," the Condition parameter cannot be set to "New".
	 * 	Brand - _string_ (Optional) Name of a brand associated with the item. You can enter all or part of the name.
	 * 	BrowseNode - _integer_ (Optional) Browse nodes are positive integers that identify product categories.
	 * 	City - _string_ (Optional) Name of a city associated with the item. You can enter all or part of the name. This parameter only works in the US locale.
	 * 	Composer - _string_ (Optional) Name of an composer associated with the item. You can enter all or part of the name.
	 * 	Condition - _string_ (Optional) Use the Condition parameter to filter the offers returned in the product list by condition type. By default, Condition equals "New". If you do not get results, consider changing the value to "All. When the Availability parameter is set to "Available," the Condition parameter cannot be set to "New". ItemSearch returns up to ten search results at a time. Allows 'New', 'Used', 'Collectible', 'Refurbished', 'All'.
	 * 	Conductor - _string_ (Optional) Name of a conductor associated with the item. You can enter all or part of the name.
	 * 	ContentType - _string_ (Optional) Specifies the format of the content in the response. Generally, ContentType should only be changed for REST requests when the Style parameter is set to an XSLT stylesheet. For example, to transform your Amazon Associates Web Service response into HTML, set ContentType to text/html. Allows 'text/xml' and 'text/html'. Defaults to 'text/xml'.
	 * 	Director - _string_ (Optional) Name of a director associated with the item. You can enter all or part of the name.
	 * 	ItemPage - _integer_ (Optional) Retrieves a specific page of items from all of the items in a response. Up to ten items are returned on a page unless Condition equals "All." In that case, returns up to three results per Condition, for example, three new, three used, three refurbished, and three collectible items. Or, for example, if there are no collectible or refurbished items being offered, returns three new and three used items. The total number of pages of items found is returned in the TotalPages response tag. Allows 1 through 400.
	 * 	Keywords - _string_ (Optional) A word or phrase associated with an item. The word or phrase can be in various product fields, including product title, author, artist, description, manufacturer, and so forth. When, for example, the search index equals "MusicTracks," the Keywords parameter enables you to search by song title.
	 * 	Manufacturer - _string_ (Optional) Name of a manufacturer associated with the item. You can enter all or part of the name.
	 * 	MaximumPrice - _string_ (Optional) Specifies the maximum price of the items in the response. Prices are in terms of the lowest currency denomination, for example, pennies.
	 * 	MerchantId - _string_ (Optional) An alphanumeric token distributed by Amazon that uniquely identifies a merchant. Allows 'All', 'Amazon', 'FeaturedBuyBoxMerchant', or a specific Merchant ID. Defaults to 'Amazon'.
	 * 	MinimumPrice - _string_ (Optional) Specifies the minimum price of the items in the response. Prices are in terms of the lowest currency denomination, for example, pennies.
	 * 	Neighborhood - _string_ (Optional) Name of a neighborhood You can enter all or part of the name. The neighborhoods are located in one of the valid values for City.
	 * 	Orchestra - _string_ (Optional) Name of an orchestra associated with the item. You can enter all or part of the name.
	 * 	PostalCode - _string_ (Optional) Postal code of the merchant. In the US, the postal code is the postal code. This parameter enables you to search for items sold in a specified region of a country.
	 * 	Power - _string_ (Optional) Performs a book search using a complex query string. Only works when the search index is set equal to "Books".
	 * 	Publisher - _string_ (Optional) Name of a publisher associated with the item. You can enter all or part of the name.
	 * 	RelatedItemsPage - _integer_ (Optional) This optional parameter is only valid when the RelatedItems response group is used. Each ItemLookup request can return, at most, ten related items. The RelatedItemsPage value specifies the set of ten related items to return. A value of 2, for example, returns the second set of ten related items.
	 * 	RelationshipType - _string_ (Optional; Required when RelatedItems response group is used) This parameter is required when the RelatedItems response group is used. The type of related item returned is specified by the RelationshipType parameter. Sample values include Episode, Season, and Tracks. A complete list of values follows this table.
	 * 	ResponseGroup - _string_ (Optional) Specifies the types of values to return. You can specify multiple response groups in one request by separating them with commas.
	 * 	returnCurlHandle - _boolean_ (Optional) A private toggle that will return the CURL handle for the request rather than actually completing the request. This is useful for MultiCURL requests.
	 * 	ReviewSort - _string_ (Optional) Sorts reviews based on the value of the parameter. '-HelpfulVotes', 'HelpfulVotes', '-OverallRating', 'OverallRating', 'Rank', '-Rank', '-SubmissionDate', 'SubmissionDate'.
	 * 	SearchIndex - _string_ (Optional) The product category to search. Many ItemSearch parameters are valid with only specific values of SearchIndex.
	 * 	Sort - _string_ (Optional) Means by which the items in the response are ordered.
	 * 	Style - _string_ (Optional) Controls the format of the data returned in Amazon Associates Web Service responses. Set this parameter to "XML," the default, to generate a pure XML response. Set this parameter to the URL of an XSLT stylesheet to have Amazon Associates Web Service transform the XML response. See ContentType.
	 * 	TagPage - _integer_ (Optional) Specifies the page of results to return. There are ten results on a page. The maximum page number is 400.
	 * 	TagsPerPage - _integer_ (Optional) The number of tags to return that are associated with a specified item.
	 * 	TagSort - _string_ (Optional) Specifies the sorting order for the results. Allows 'FirstUsed', '-FirstUsed', 'LastUsed', '-LastUsed', 'Name', '-Name', 'Usages', and '-Usages'. To sort items in descending order, prefix the values with a negative sign (-).
	 * 	TextStream - _string_ (Optional) A search based on two or more words. Picks out of the block of text up to ten keywords and returns up to ten items that match those keywords. For example, if five keywords are found, two items for each keyword are returned. Only one page of results is returned so ItemPage does not work with TextStream.
	 * 	Title - _string_ (Optional) The title associated with the item. You can enter all or part of the title. Title searches are a subset of Keyword searches. If a Title search yields insufficient results, consider using a Keywords search.
	 * 	Validate - _boolean_ (Optional) Prevents an operation from executing. Set the Validate parameter to True to test your request without actually executing it. When present, Validate must equal True; the default value is False. If a request is not actually executed (Validate=True), only a subset of the errors for a request may be returned because some errors (for example, no_exact_matches) are only generated during the execution of a request. Defaults to FALSE.
	 * 	VariationPage - _integer_ (Optional) Retrieves a specific page of variations returned by ItemSearch. By default, ItemSearch returns all variations. Use VariationPage to return a subsection of the response. There are 10 variations per page. To examine offers 11 trough 20, for example, set VariationPage to 2. The total number of pages is returned in the TotalPages element.
	 * 	XMLEscaping - _string_ (Optional) Specifies whether responses are XML-encoded in a single pass or a double pass. By default, XMLEscaping is Single, and Amazon Associates Web Service responses are encoded only once in XML. For example, if the response data includes an ampersand character (&), the character is returned in its regular XML encoding (&). If XMLEscaping is Double, the same ampersand character is XML-encoded twice (&amp;). The Double value for XMLEscaping is useful in some clients, such as PHP, that do not decode text within XML elements. Defaults to 'Single'.
	 *
	 * Returns:
	 * 	<CFResponse> object
	 *
	 * See Also:
	 * 	[ItemSearch Operation](http://docs.amazonwebservices.com/AWSECommerceService/latest/DG/ItemSearch.html)
	 */
	public function item_search($keywords, $opt = null)
	{
		if (!$opt) $opt = array();
		$opt['Keywords'] = $keywords;

		if (isset($this->assoc_id))
		{
			$opt['AssociateTag'] = $this->assoc_id;
		}

		if (!isset($opt['SearchIndex']) || empty($opt['SearchIndex']))
		{
			$opt['SearchIndex'] = 'All';
		}

		return $this->pas_authenticate('ItemSearch', $opt);
	}


	/*%******************************************************************************************%*/
	// SELLER METHODS

	/**
	 * Method: seller_listing_lookup()
	 * 	Enables you to return information about a seller's listings, including product descriptions, availability, condition, and quantity available. The response also includes the seller's nickname. Each request requires a seller ID.
	 *
	 * 	You can also find a seller's items using ItemLookup. There are, however, some reasons why it is better to use <seller_listing_lookup()>: (a) <seller_listing_lookup()> enables you to search by seller ID. (b) <seller_listing_lookup()> returns much more information than <item_lookup()>.
	 *
	 * 	This operation only works with sellers who have less than 100,000 items for sale. Sellers that have more items for sale should use, instead of Amazon Associates Web Service, other APIs, including the Amazon Inventory Management System, and the Merchant@ API.
	 *
	 * 	If you added your Associates ID to the config.inc.php file, or you passed it into the AmazonPAS() constructor, it will be passed along in this request automatically.
	 *
	 * Access:
	 * 	public
	 *
	 * Parameters:
	 * 	$item_id - _string_ (Optional) Number that uniquely identifies an item. The valid value depends on the value for IdType. Allows an Exchange ID, a Listing ID, an ASIN, or a SKU.
	 * 	$id_type - _string_ (Optional) Use the IdType parameter to specify the value type of the Id parameter value. If you are looking up an Amazon Marketplace item, use Exchange, ASIN, or SKU as the value for IdType. Discontinued, out of stock, or unavailable products will not be returned if IdType is Listing, SKU, or ASIN. Those products will be returned, however, if IdType is Exchange. Allows 'Exchange', 'Listing', 'ASIN', 'SKU'.
	 * 	$seller_id - _string_ (Optional) Alphanumeric token that uniquely identifies a seller. This parameter limits the results to a single seller ID.
	 * 	$opt - _array_ (Optional) Associative array of parameters which can have the following keys:
	 *
	 * Keys for the $opt parameter:
	 * 	THIS IS AN INCOMPLETE LIST. For the latest information, check the AWS documentation page (noted below), or run the <help()> method (noted in the examples below).
	 *
	 * 	ContentType - _string_ (Optional) Specifies the format of the content in the response. Generally, ContentType should only be changed for REST requests when the Style parameter is set to an XSLT stylesheet. For example, to transform your Amazon Associates Web Service response into HTML, set ContentType to text/html. Allows 'text/xml' and 'text/html'. Defaults to 'text/xml'.
	 * 	MerchantId - _string_ (Optional) An alphanumeric token distributed by Amazon that uniquely identifies a merchant. Allows 'All', 'Amazon', 'FeaturedBuyBoxMerchant', or a specific Merchant ID. Defaults to 'Amazon'.
	 *  ResponseGroup - _string_ (Optional) Specifies the types of values to return. You can specify multiple response groups in one request by separating them with commas.
	 * 	returnCurlHandle - _boolean_ (Optional) A private toggle that will return the CURL handle for the request rather than actually completing the request. This is useful for MultiCURL requests.
	 * 	Style - _string_ (Optional) Controls the format of the data returned in Amazon Associates Web Service responses. Set this parameter to "XML," the default, to generate a pure XML response. Set this parameter to the URL of an XSLT stylesheet to have Amazon Associates Web Service transform the XML response. See ContentType.
	 * 	Validate - _boolean_ (Optional) Prevents an operation from executing. Set the Validate parameter to True to test your request without actually executing it. When present, Validate must equal True; the default value is False. If a request is not actually executed (Validate=True), only a subset of the errors for a request may be returned because some errors (for example, no_exact_matches) are only generated during the execution of a request. Defaults to FALSE.
	 * 	XMLEscaping - _string_ (Optional) Specifies whether responses are XML-encoded in a single pass or a double pass. By default, XMLEscaping is Single, and Amazon Associates Web Service responses are encoded only once in XML. For example, if the response data includes an ampersand character (&), the character is returned in its regular XML encoding (&). If XMLEscaping is Double, the same ampersand character is XML-encoded twice (&amp;). The Double value for XMLEscaping is useful in some clients, such as PHP, that do not decode text within XML elements. Defaults to 'Single'.
	 *
	 * Returns:
	 * 	<CFResponse> object
	 *
	 * See Also:
	 * 	[SellerListingLookup Operation](http://docs.amazonwebservices.com/AWSECommerceService/latest/DG/SellerListingLookup.html)
	 */
	public function seller_listing_lookup($item_id, $id_type, $seller_id, $opt = null)
	{
		if (!$opt) $opt = array();
		$opt['Id'] = $item_id;
		$opt['IdType'] = $id_type;
		$opt['SellerId'] = $seller_id;

		if (isset($this->assoc_id))
		{
			$opt['AssociateTag'] = $this->assoc_id;
		}

		return $this->pas_authenticate('SellerListingLookup', $opt);
	}

	/**
	 * Method: seller_listing_search()
	 * 	Enables you to search for items offered by specific sellers. You cannot use <seller_listing_search()> to look up items sold by merchants. To look up an item sold by a merchant, use <item_lookup()> or <item_search()> along with the MerchantId parameter.
	 *
	 * 	<seller_listing_search()> returns the listing ID or exchange ID of an item. Typically, you use those values with <seller_listing_lookup()> to find out more about those items.
	 *
	 * 	Each request returns up to ten items. By default, the first ten items are returned. You can use the ListingPage parameter to retrieve additional pages of (up to) ten listings. To use Amazon Associates Web Service, sellers must have less than 100,000 items for sale. Sellers that have more items for sale should use, instead of Amazon Associates Web Service, other seller APIs, including the Amazon Inventory Management System, and the Merchant@ API.
	 *
	 * 	<seller_listing_search()> requires a seller ID, which means that you cannot use this operation to search across all sellers. Amazon Associates Web Service does not have a seller-specific operation that does this. To search across all sellers, use <item_lookup()> or <item_search()>.
	 *
	 * 	If you added your Associates ID to the config.inc.php file, or you passed it into the AmazonPAS() constructor, it will be passed along in this request automatically.
	 *
	 * Access:
	 * 	public
	 *
	 * Parameters:
	 * 	$seller_id - _string_ (Required) An alphanumeric token that uniquely identifies a seller. These tokens are created by Amazon and distributed to sellers.
	 * 	$opt - _array_ (Optional) Associative array of parameters which can have the following keys:
	 *
	 * Keys for the $opt parameter:
	 * 	THIS IS AN INCOMPLETE LIST. For the latest information, check the AWS documentation page (noted below), or run the <help()> method (noted in the examples below).
	 *
	 * 	ContentType - _string_ (Optional) Specifies the format of the content in the response. Generally, ContentType should only be changed for REST requests when the Style parameter is set to an XSLT stylesheet. For example, to transform your Amazon Associates Web Service response into HTML, set ContentType to text/html. Allows 'text/xml' and 'text/html'. Defaults to 'text/xml'.
	 * 	ListingPage - _integer_ (Optional) Page of the response to return. Up to ten lists are returned per page. For customers that have more than ten lists, more than one page of results are returned. By default, the first page is returned. To return another page, specify the page number. Allows 1 through 500.
	 * 	MerchantId - _string_ (Optional) An alphanumeric token distributed by Amazon that uniquely identifies a merchant. Allows 'All', 'Amazon', 'FeaturedBuyBoxMerchant', or a specific Merchant ID. Defaults to 'Amazon'.
	 * 	OfferStatus - _string_ (Optional) Specifies whether the product is available (Open), or not (Closed.) Closed products are those that are discontinued, out of stock, or unavailable. Defaults to 'Open'.
	 * 	ResponseGroup - _string_ (Optional) Specifies the types of values to return. You can specify multiple response groups in one request by separating them with commas.
	 * 	returnCurlHandle - _boolean_ (Optional) A private toggle that will return the CURL handle for the request rather than actually completing the request. This is useful for MultiCURL requests.
	 * 	Sort - _string_ (Optional) Use the Sort parameter to specify how your seller listing search results will be ordered. The -bfp (featured listings - default), applies only to the US, UK, and DE locales. Allows '-startdate', 'startdate', '+startdate', '-enddate', 'enddate', '-sku', 'sku', '-quantity', 'quantity', '-price', 'price |+price', '-title', 'title'.
	 * 	Style - _string_ (Optional) Controls the format of the data returned in Amazon Associates Web Service responses. Set this parameter to "XML," the default, to generate a pure XML response. Set this parameter to the URL of an XSLT stylesheet to have Amazon Associates Web Service transform the XML response. See ContentType.
	 * 	Title - _string_ (Optional) Searches for products based on the product's name. Keywords and Title are mutually exclusive; you can have only one of the two in a request.
	 * 	Validate - _boolean_ (Optional) Prevents an operation from executing. Set the Validate parameter to True to test your request without actually executing it. When present, Validate must equal True; the default value is False. If a request is not actually executed (Validate=True), only a subset of the errors for a request may be returned because some errors (for example, no_exact_matches) are only generated during the execution of a request. Defaults to FALSE.
	 * 	XMLEscaping - _string_ (Optional) Specifies whether responses are XML-encoded in a single pass or a double pass. By default, XMLEscaping is Single, and Amazon Associates Web Service responses are encoded only once in XML. For example, if the response data includes an ampersand character (&), the character is returned in its regular XML encoding (&). If XMLEscaping is Double, the same ampersand character is XML-encoded twice (&amp;). The Double value for XMLEscaping is useful in some clients, such as PHP, that do not decode text within XML elements. Defaults to 'Single'.
	 *
	 * Returns:
	 * 	<CFResponse> object
	 *
	 * See Also:
	 * 	[SellerListingSearch Operation](http://docs.amazonwebservices.com/AWSECommerceService/latest/DG/SellerListingSearch.html)
	 */
	public function seller_listing_search($seller_id, $opt = null)
	{
		if (!$opt) $opt = array();
		$opt['SellerId'] = $seller_id;

		if (isset($this->assoc_id))
		{
			$opt['AssociateTag'] = $this->assoc_id;
		}

		return $this->pas_authenticate('SellerListingSearch', $opt);
	}

	/**
	 * Method: seller_lookup()
	 * 	Returns detailed information about sellers and, in the US locale, merchants. To lookup a seller, you must use their seller ID. The information returned includes the seller's name, average rating by customers, and the first five customer feedback entries. <seller_lookup()> will not, however, return the seller's e-mail or business addresses.
	 *
	 * 	A seller must enter their information. Sometimes, sellers do not. In that case, <seller_lookup()> cannot return some seller-specific information.
	 *
	 * 	To look up more than one seller in a single request, insert a comma-delimited list of up to five seller IDs in the SellerId parameter of the REST request. Customers can rate sellers. 5 is the best rating; 0 is the worst. The rating reflects the customer's experience with the seller. The <seller_lookup()> operation, by default, returns review comments by individual customers.
	 *
	 * 	If you added your Associates ID to the config.inc.php file, or you passed it into the AmazonPAS() constructor, it will be passed along in this request automatically.
	 *
	 * Access:
	 * 	public
	 *
	 * Parameters:
	 * 	$seller_id - _string_ (Required) An alphanumeric token that uniquely identifies a seller. These tokens are created by Amazon and distributed to sellers.
	 * 	$opt - _array_ (Optional) Associative array of parameters which can have the following keys:
	 *
	 * Keys for the $opt parameter:
	 * 	THIS IS AN INCOMPLETE LIST. For the latest information, check the AWS documentation page (noted below), or run the <help()> method (noted in the examples below).
	 *
	 * 	ContentType - _string_ (Optional) Specifies the format of the content in the response. Generally, ContentType should only be changed for REST requests when the Style parameter is set to an XSLT stylesheet. For example, to transform your Amazon Associates Web Service response into HTML, set ContentType to text/html. Allows 'text/xml' and 'text/html'. Defaults to 'text/xml'.
	 * 	FeedbackPage - _string_ (Optional) Specifies the page of reviews to return. Up to five reviews are returned per page. The first page is returned by default. To access additional pages, use this parameter to specify the desired page. The maximum number of pages that can be returned is 10 (50 feedback items). Allows 1 through 10.
	 * 	MerchantId - _string_ (Optional) An alphanumeric token distributed by Amazon that uniquely identifies a merchant. Allows 'All', 'Amazon', 'FeaturedBuyBoxMerchant', or a specific Merchant ID. Defaults to 'Amazon'.
	 * 	ResponseGroup - _string_ (Optional) Specifies the types of values to return. You can specify multiple response groups in one request by separating them with commas.
	 * 	returnCurlHandle - _boolean_ (Optional) A private toggle that will return the CURL handle for the request rather than actually completing the request. This is useful for MultiCURL requests.
	 * 	Style - _string_ (Optional) Controls the format of the data returned in Amazon Associates Web Service responses. Set this parameter to "XML," the default, to generate a pure XML response. Set this parameter to the URL of an XSLT stylesheet to have Amazon Associates Web Service transform the XML response. See ContentType.
	 * 	Validate - _boolean_ (Optional) Prevents an operation from executing. Set the Validate parameter to True to test your request without actually executing it. When present, Validate must equal True; the default value is False. If a request is not actually executed (Validate=True), only a subset of the errors for a request may be returned because some errors (for example, no_exact_matches) are only generated during the execution of a request. Defaults to FALSE.
	 * 	XMLEscaping - _string_ (Optional) Specifies whether responses are XML-encoded in a single pass or a double pass. By default, XMLEscaping is Single, and Amazon Associates Web Service responses are encoded only once in XML. For example, if the response data includes an ampersand character (&), the character is returned in its regular XML encoding (&). If XMLEscaping is Double, the same ampersand character is XML-encoded twice (&amp;). The Double value for XMLEscaping is useful in some clients, such as PHP, that do not decode text within XML elements. Defaults to 'Single'.
	 *
	 * Returns:
	 * 	<CFResponse> object
	 *
	 * See Also:
	 * 	[SellerLookup Operation](http://docs.amazonwebservices.com/AWSECommerceService/latest/DG/SellerLookup.html)
	 */
	public function seller_lookup($seller_id, $opt = null)
	{
		if (!$opt) $opt = array();
		$opt['SellerId'] = $seller_id;

		if (isset($this->assoc_id))
		{
			$opt['AssociateTag'] = $this->assoc_id;
		}

		return $this->pas_authenticate('SellerLookup', $opt);
	}


	/*%******************************************************************************************%*/
	// OTHER LOOKUP METHODS

	/**
	 * Method: similarity_lookup()
	 * 	Returns up to ten products per page that are similar to one or more items specified in the request. This operation is typically used to pique a customer's interest in buying something similar to what they've already ordered.
	 *
	 * 	If you specify more than one item, <similarity_lookup()> returns the intersection of similar items each item would return separately. Alternatively, you can use the SimilarityType parameter to return the union of items that are similar to any of the specified items. A maximum of ten similar items are returned; the operation does not return additional pages of similar items. If there are more than ten similar items, running the same request can result in different answers because the ten that are included in the response are picked randomly. The results are picked randomly only when you specify multiple items and the results include more than ten similar items.
	 *
	 * 	When you specify multiple items, it is possible for there to be no intersection of similar items. In this case, the operation returns an error.
	 *
	 * 	Similarity is a measurement of similar items purchased, that is, customers who bought X also bought Y and Z. It is not a measure, for example, of items viewed, that is, customers who viewed X also viewed Y and Z.
	 *
	 * 	If you added your Associates ID to the config.inc.php file, or you passed it into the AmazonPAS() constructor, it will be passed along in this request automatically.
	 *
	 * Access:
	 * 	public
	 *
	 * Parameters:
	 * 	$item_id - _string_ (Required) Specifies the item you want to look up. An ItemId is an alphanumeric identifier assigned to an item. You can specify up to ten ItemIds separated by commas.
	 * 	$opt - _array_ (Optional) Associative array of parameters which can have the following keys:
	 *
	 * Keys for the $opt parameter:
	 * 	THIS IS AN INCOMPLETE LIST. For the latest information, check the AWS documentation page (noted below), or run the <help()> method (noted in the examples below).
	 *
	 * 	Condition - _string_ (Optional) Specifies an item's condition. If Condition is set to "All", a separate set of responses is returned for each valid value of Condition. Allows 'All', 'Collectible', 'Refurbished', or 'Used'.
	 * 	ContentType - _string_ (Optional) Specifies the format of the content in the response. Generally, ContentType should only be changed for REST requests when the Style parameter is set to an XSLT stylesheet. For example, to transform your Amazon Associates Web Service response into HTML, set ContentType to text/html. Allows 'text/xml' and 'text/html'. Defaults to 'text/xml'.
	 * 	MerchantId - _string_ (Optional) Specifies the merchant who is offering the item. MerchantId is an alphanumeric identifier assigned by Amazon to merchants. Make sure to use a Merchant ID and not a Seller ID. Seller IDs are not supported.
	 * 	ResponseGroup - _string_ (Optional) Specifies the types of values to return. You can specify multiple response groups in one request by separating them with commas.
	 * 	returnCurlHandle - _boolean_ (Optional) A private toggle that will return the CURL handle for the request rather than actually completing the request. This is useful for MultiCURL requests.
	 * 	SimilarityType - _string_ (Optional) "Intersection" returns the intersection of items that are similar to all of the ASINs specified. "Random" returns the union of items that are similar to all of the ASINs specified. Only ten items are returned. So, if there are more than ten similar items found, a random selection from the group is returned. For this reason, running the same request multiple times can yield different results.
	 * 	Style - _string_ (Optional) Controls the format of the data returned in Amazon Associates Web Service responses. Set this parameter to "XML," the default, to generate a pure XML response. Set this parameter to the URL of an XSLT stylesheet to have Amazon Associates Web Service transform the XML response. See ContentType.
	 * 	Validate - _boolean_ (Optional) Prevents an operation from executing. Set the Validate parameter to True to test your request without actually executing it. When present, Validate must equal True; the default value is False. If a request is not actually executed (Validate=True), only a subset of the errors for a request may be returned because some errors (for example, no_exact_matches) are only generated during the execution of a request. Defaults to FALSE.
	 * 	XMLEscaping - _string_ (Optional) Specifies whether responses are XML-encoded in a single pass or a double pass. By default, XMLEscaping is Single, and Amazon Associates Web Service responses are encoded only once in XML. For example, if the response data includes an ampersand character (&), the character is returned in its regular XML encoding (&). If XMLEscaping is Double, the same ampersand character is XML-encoded twice (&amp;). The Double value for XMLEscaping is useful in some clients, such as PHP, that do not decode text within XML elements. Defaults to 'Single'.
	 *
	 * Returns:
	 * 	<CFResponse> object
	 *
	 * See Also:
	 * 	[SimilarityLookup Operation](http://docs.amazonwebservices.com/AWSECommerceService/latest/DG/SimilarityLookup.html)
	 */
	function similarity_lookup($item_id, $opt = null)
	{
		if (!$opt) $opt = array();
		$opt['ItemId'] = $item_id;

		if (isset($this->assoc_id))
		{
			$opt['AssociateTag'] = $this->assoc_id;
		}

		return $this->pas_authenticate('SimilarityLookup', $opt);
	}
}