<?php declare(strict_types=1);

namespace OrderByLink\Subscriber;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\PriceDefinition;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;

class FrontendSubscriber implements EventSubscriberInterface
{

    private CartService $cartService;
    private EntityRepository $productRepository;
    private RequestStack $requestStack;
    private SystemConfigService $systemConfigService;
    private LoggerInterface $logger;
    private Context $context;
    private $saleChannelContextService;
    private $salesChannelContext;

    public function __construct(
        CartService $cartService,
        EntityRepository $productRepository,
        RequestStack $requestStack,
        SystemConfigService $systemConfigService,
        LoggerInterface $logger,
        SalesChannelContextService $salesChannelContextService,
        SalesChannelContext $salesChannelContext,
    ) {

        $this->cartService = $cartService;
        $this->productRepository = $productRepository;
        $this->requestStack = $requestStack;
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
        $this->saleChannelContextService = $salesChannelContextService;
        $this->salesChannelContext = $salesChannelContext;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            RequestEvent::class => 'onPostDispatchSecureFrontend',
        ];
    }

    public function onPostDispatchSecureFrontend(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->isXmlHttpRequest() && $request->getPathInfo() === '/') {
            $gArticle = $request->get('gArticle');


            if ($gArticle) {
                $config = $this->systemConfigService->get('OrderByLink.config.ArticleAndPrices');
                $articleAndPrices = explode(";", $config);
                $articleAndPricesTrimmed = array_map('trim', $articleAndPrices);

                foreach ($articleAndPricesTrimmed as $value) {
                    $arguments = explode("=", $value);
                    if (strcmp($arguments[0], $gArticle) == 0 && $arguments[0] != "") {
                        try {
                            $this->addArticleToBasketByOrdernumber($gArticle, $arguments[1], $arguments[2]);

                            // Redirect to checkout
                            $response = new RedirectResponse('/checkout/cart');
                            $event->setResponse($response);
                            return;
                        } catch (\Exception $e) {
                            $this->logger->error('Error adding article to basket: ' . $e->getMessage());

                            // Inject a script to log the error in the console
                            $response = $event->getResponse();
                            if ($response && strpos($response->headers->get('Content-Type'), 'text/html') !== false) {
                                $responseContent = '<script>console.error("Error adding article to basket: ' . $e->getMessage() . '");</script>';
                                $responseContent .= $response->getContent();
                                $response->setContent($responseContent);
                            }
                        }
                    }
                }
            }
        }
    }

    public function addArticleToBasketByOrdernumber(string $orderNumber, string $price, string $shipping): void
    {

        $request = $this->requestStack->getCurrentRequest();

        $salesChannelId = $this->salesChannelContext->getSalesChannel()->getId();
        $token = $this->salesChannelContext->getToken();
        $languageId = $this->salesChannelContext->getLanguageId();
        $currency = $this->salesChannelContext->getCurrency()->getId();
        $context = $this->salesChannelContext->getContext();

        $parameters = new SalesChannelContextServiceParameters(
            $salesChannelId,
            $token,
            $languageId,
            $currency,
            $context
        );
            $salesChannelContext = $this->saleChannelContextService->get($parameters);

        $this->logger->info('Context: ' . $salesChannelContext);

        if (!$contextToken) {
            throw new \RuntimeException('Sales channel context not found');
        }
        $this->logger->info('cart service' . json_encode($this->cartService));
        // Get the sales channel context from the token
        $salesChannelContext = $this->cartService->getContext($contextToken);
        if (!$salesChannelContext) {
            throw new \RuntimeException('Invalid sales channel context');
        }

        // Fetch product by order number
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('productNumber', $orderNumber));
        $product = $this->productRepository->search($criteria, $contextToken)->first();

        if (!$product instanceof ProductEntity) {
            $this->logger->error('Product not found for order number: ' . $orderNumber);
            throw new \RuntimeException('Product not found with order number ' . $orderNumber);
        }

        // Convert price
        $parsedPrice = floatval(str_replace(',', '.', $price));
        if ($parsedPrice <= 0) {
            $this->logger->error('Invalid price: ' . $price);
            throw new \InvalidArgumentException('Invalid price: ' . $price);
        }

        // Get or create Cart
        $cart = $this->cartService->getCart($contextToken, $salesChannelContext);

        $lineItem = new LineItem($product->getId(), LineItem::PRODUCT_LINE_ITEM_TYPE, $product->getId());
        $lineItem->setLabel($product->getName());

        // Set price using PriceDefinition
        $priceDefinition = new PriceDefinition($parsedPrice, $salesChannelContext->getCurrency()->getId(), $salesChannelContext->getTaxState());
        $lineItem->setPriceDefinition($priceDefinition);
        $lineItem->setQuantity(1);

        // Set payload
        $lineItem->setPayloadValue('shippingfee', (bool)$shipping);

        $cart->add($lineItem);

        $updatedCart = $this->cartService->update($cart->getToken(), $cart, $salesChannelContext);
        if (!$updatedCart->has($lineItem->getId())) {
            $this->logger->error('Failed to add line item to the cart');
            throw new \RuntimeException('Failed to add line item to the cart');
        }

        $this->logger->info('Successfully added article with order number: ' . $orderNumber . ' to the cart.');
    }

}
