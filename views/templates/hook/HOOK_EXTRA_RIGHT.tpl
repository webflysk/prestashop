<div itemtype="http://schema.org/AggregateRating" itemscope="" itemprop="aggregateRating" class="no-print"
     id="product_cofidis_block_extra">
    <ul class="comments_advices">
        <li>
            <a href="{$url|escape:'htmlall':'UTF-8'}"
               id="cofidisinfo" title="Splátková kalkulačka Cofidis">Vypočítajte si splátky Cofidis</a></li>
        <li>Zakúpte si tento produkt na pôžičku Cofidis.</li>
    </ul>
</div>

{literal}
    <script type="text/javascript">
        $(document).ready(function () {

            $(document).on('click', '#product_cofidis_block_extra a', function (e) {
                e.preventDefault();
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
                        'href': url + '',
                    });
            });

        });
    </script>
{/literal}