{*
* 2007-2016 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2016 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*}

{capture name=path}
    <a href="{$link->getPageLink('order', true, NULL, "step=3")|escape:'html':'UTF-8'}"
       title="{l s='Go back to the Checkout' mod='lnccofidis'}">{l s='Checkout' mod='lnccofidis'}</a>
    <span class="navigation-pipe">{$navigationPipe}</span>{l s='Nákup na splátky prostredníctvom Cofidis' mod='lnccofidis'}
{/capture}

<h1 class="page-heading">
    {l s='Order summary' mod='lnccofidis'}
</h1>

{assign var='current_step' value='payment'}
{include file="$tpl_dir./order-steps.tpl"}

{if $nbProducts <= 0}
    <p class="alert alert-warning">
        {l s='Your shopping cart is empty.' mod='lnccofidis'}
    </p>
{else}
    <form action="{$link->getModuleLink('LNCCofidis', 'validation', [], true)|escape:'html':'UTF-8'}" method="post">
        <div class="box cheque-box">
            <h3 class="page-subheading">
                {l s='Žiadosť o pôžičku Cofidis' mod='lnccofidis'}
            </h3>
            <p class="cheque-indent">
                <strong class="dark">
                    {l s='Vybrali ste si spôsob platby nákupu na splátky prostredníctvom Cofidis.' mod='lnccofidis'}
                </strong>
            </p>
            <p>
                - {l s='The total amount of your order is' mod='lnccofidis'}
                <span id="amount" class="price">{displayPrice price=$total}</span>
                {if $use_taxes == 1}
                    {l s='(tax incl.)' mod='lnccofidis'}
                {/if}
            </p>
            <p>
                - {l s='V nasledujúcom kroku budete mať možnosť podať žiadosť o pôžičku Cofidis.' mod='lnccofidis'}
                <br/>
                - {l s='Please confirm your order by clicking "I confirm my order".' mod='lnccofidis'}
            </p>
        </div><!-- .cheque-box -->
        <p class="cart_navigation clearfix" id="cart_navigation">
            <a class="button-exclusive btn btn-default"
               href="{$link->getPageLink('order', true, NULL, "step=3")|escape:'html':'UTF-8'}">
                <i class="icon-chevron-left"></i>{l s='Other payment methods' mod='lnccofidis'}
            </a>
            <button class="button btn btn-default button-medium" type="submit">
                <span>{l s='I confirm my order' mod='lnccofidis'}<i class="icon-chevron-right right"></i></span>
            </button>
        </p>
    </form>
{/if}

{if isset($url)}

{literal}
    <script type="text/javascript">
        $(document).ready(function () {

            var url = this.href;
            if (!!$.prototype.fancybox)
                $.fancybox({
                    'padding': 20,
                    'width': '90%',
                    'height': '90%',
                    'autoScale': false,
                    'transitionIn': 'none',
                    'transitionOut': 'none',
                    'type': 'iframe',
                    'href': {/literal}{$url|@json_encode}{literal},
                });

        });
    </script>
{/literal}

{/if}
