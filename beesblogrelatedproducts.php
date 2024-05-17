<?php
/**
 * Copyright (C) 2017-2024 thirty bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * @author    thirty bees <modules@thirtybees.com>
 * @copyright 2017-2024 thirty bees
 * @license   Academic Free License (AFL 3.0)
 */

if (!defined('_TB_VERSION_')) {
    exit;
}

/**
 * Class BeesBlogRelatedProducts
 */
class BeesBlogRelatedProducts extends Module
{
    const PRODUCT_CACHE_KEY = 'BeesBlogRelatedProducts_PRODUCT_';
    const BLOG_POST_CACHE_KEY = 'BeesBlogRelatedProducts_POST_';
    const PRODUCT_LIMIT_KEY = 'BBRP_PRODUCT_LIMIT';
    const BLOG_LIMIT_KEY = 'BBRP_BLOG_LIMIT';

    /**
     * BeesBlogRelatedProducts constructor.
     * @throws PrestaShopException
     */
    public function __construct()
    {
        $this->name = 'beesblogrelatedproducts';
        $this->tab = 'front_office_features';
        $this->version = '1.1.1';
        $this->author = 'thirty bees';

        $this->bootstrap = true;

        parent::__construct();
        $this->displayName = $this->l('Bees Blog Related Products');
        $this->description = $this->l('thirty bees blog related products widget');
        $this->dependencies = ['beesblog'];
        $this->need_instance = false;
        $this->tb_versions_compliancy = '>= 1.0.0';
        $this->tb_min_version = '1.0.0';
    }

    /**
     * Installs module
     *
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function install()
    {
        return (
            parent::install() &&
            $this->registerHook('displayFooterProduct') &&
            $this->registerHook('displayBeesBlogAfterPost')
        );
    }

    /**
     * Hook to display related blog post on product page
     *
     * @return string
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function hookDisplayFooterProduct()
    {
        if (!Module::isEnabled('beesblog')) {
            return null;
        }
        $posts = $this->getBlogPostsForProduct((int)Tools::getValue('id_product'));
        if ($posts) {
            $this->context->smarty->assign('blog_posts', $posts);
            return $this->display(__FILE__, 'views/templates/hooks/product.tpl');
        }
        return null;
    }

    /**
     * Hook to display related products on blog post page
     *
     * @param $data
     * @return string
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function hookDisplayBeesBlogAfterPost($data)
    {
        if (!Module::isEnabled('beesblog')) {
            return null;
        }
        $products = $this->getProductsForPost((int)$data['post']->id);
        if ($products) {
            $this->context->smarty->assign('related_products', $products);
            return $this->display(__FILE__, 'views/templates/hooks/blog_post.tpl');
        }
        return null;
    }


    /**
     * @return string
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function displayForm()
    {
        $settingsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Settings'),
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Product limits'),
                        'name' => static::PRODUCT_LIMIT_KEY,
                        'desc' => $this->l('Number of related products to be displayed under blog post'),
                        'required' => true
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Blog limits'),
                        'name' => static::BLOG_LIMIT_KEY,
                        'desc' => $this->l('Number of related blog posts to be displayed on product page'),
                        'required' => true
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right'
                ]
            ]
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
        $helper->title = $this->displayName;
        $helper->show_toolbar = false;
        $helper->toolbar_scroll = false;
        $helper->submit_action = 'submit'.$this->name;

        $helper->fields_value = [
            static::PRODUCT_LIMIT_KEY => $this->getProductLimit(),
            static::BLOG_LIMIT_KEY => $this->getBlogLimit()
        ];
        return $helper->generateForm([
            $settingsForm
        ]);
    }

    /**
     * @return string
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submit'.$this->name)) {
            $productLimit = static::normalizeLimit(Tools::getValue(static::PRODUCT_LIMIT_KEY, $this->getProductLimit()));
            $blogLimit = static::normalizeLimit(Tools::getValue(static::BLOG_LIMIT_KEY, $this->getBlogLimit()));
            Configuration::updateValue(static::PRODUCT_LIMIT_KEY, $productLimit);
            Configuration::updateValue(static::BLOG_LIMIT_KEY, $blogLimit);
            $output = $this->displayConfirmation($this->l('Settings has been updated'));
        }

        return $output.$this->displayForm();
    }

    /**
     * Returns related blog posts for product id
     *
     * @param int $productId
     * @return array
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function getBlogPostsForProduct($productId)
    {
        $productId = (int)$productId;
        $lang = (int)Context::getContext()->language->id;
        $key = static::PRODUCT_CACHE_KEY . $productId . '_' . $lang;
        if (!Cache::isStored($key)) {
            $blogPosts = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS((new DbQuery())
                ->select('*')
                ->from('bees_blog_post_product', 'pp')
                ->innerJoin('bees_blog_post', 'bbp', 'bbp.id_bees_blog_post = pp.id_bees_blog_post')
                ->leftJoin('bees_blog_post_lang', 'bbpl', 'bbpl.lang_active AND bbpl.id_bees_blog_post = bbp.id_bees_blog_post AND bbpl.id_lang = '.$lang)
                ->where('pp.id_product = '.$productId)
                ->where('bbp.active')
                ->orderBy('bbp.date_add desc')
                ->limit($this->getBlogLimit())
            );
            if ($blogPosts) {
                foreach ($blogPosts as &$post) {
                    $post['link'] = BeesBlog::getBeesBlogLink('beesblog_post', ['blog_rewrite' => $post['link_rewrite']]);
                    $post['id'] = $post['id_bees_blog_post'];
                }
            }
            Cache::store($key, $blogPosts);
        }
        return Cache::retrieve($key);
    }

    /**
     * Returns related products for blog post id
     *
     * @param $postId
     * @return array
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function getProductsForPost($postId)
    {
        $postId = (int)$postId;
        $lang = (int)Context::getContext()->language->id;
        $key = static::BLOG_POST_CACHE_KEY . $postId . '_' . $lang;
        if (!Cache::isStored($key)) {
            $products = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS((new DbQuery())
                ->select('pl.*, is.id_image')
                ->from('bees_blog_post_product', 'pp')
                ->innerJoin('product', 'p', 'p.id_product = pp.id_product')
                ->leftJoin('product_lang', 'pl', 'pl.id_product = p.id_product AND pl.id_lang = '.$lang.Shop::addSqlRestrictionOnLang('pl'))
                ->leftJoin('image_shop', 'is', 'is.id_product = p.`id_product` AND is.cover=1 AND is.id_shop='.(int) $this->context->shop->id)
                ->where('pp.id_bees_blog_post = '.$postId)
                ->limit($this->getProductLimit())
            );
            if ($products) {
                foreach ($products as &$product) {
                    $product['link'] = $this->context->link->getProductLink((int)$product['id_product']);
                    $product['image'] = $this->context->link->getImageLink((string)$product['link_rewrite'], (int)$product['id_image'], ImageType::getFormatedName('home'));
                }
            }
            Cache::store($key, $products);
        }
        return Cache::retrieve($key);
    }

    /**
     * @return int
     * @throws PrestaShopException
     */
    protected function getProductLimit()
    {
        return static::getLimitValue(static::PRODUCT_LIMIT_KEY);
    }

    /**
     * @return int
     * @throws PrestaShopException
     */
    protected function getBlogLimit()
    {
        return static::getLimitValue(static::BLOG_LIMIT_KEY);
    }

    /**
     * @param $key
     *
     * @return int
     * @throws PrestaShopException
     */
    protected static function getLimitValue($key)
    {
        $value = Configuration::get($key);
        if ($value === false || $value === null) {
            return 3;
        }
        return static::normalizeLimit($value);
    }

    /**
     * @param int $value
     * @return int
     */
    protected static function normalizeLimit($value)
    {
        return max((int)$value, 0);
    }
}
