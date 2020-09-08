<?php

namespace App\Commands;

use App\Category;
use App\Product;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\DomCrawler\Crawler;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

class Craw extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'craw';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Command description';
    /**
     * @var Client
     */
    private $client;
    /**
     * @var CookieJar
     */
    private $cookie;
    /**
     * @var $categorias
     */
    private $categorias;
    /**
     * @var $produtos
     */
    private $produtos;

    /**
     * DownloadVolvoParts constructor.
     *
     * @param Client $client
     */
    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->cookie = new CookieJar();
        parent::__construct();

    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

        $this->getCategories();
        $this->getProductsList();

    }


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function getCategories()
    {
        $response = $this->client->get('https://www.submarino.com.br/mapa-do-site/categoria');

        $html       = new Crawler($response->getBody()->getContents());
        $categorias = [];
        $html->filter('.sitemap-list li')->each(function (Crawler $node, $i) use (&$categorias) {
            $categorias[] = [
                'href' => $node->filter('a')->attr('href'),
                'name' => $node->filter('a')->text()
            ];
        });

        foreach ($categorias as $categoria) {
            Category::updateOrCreate($categoria, $categoria);
        }
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function getProductsList()
    {
        $categorias = Category::all();
        foreach ($categorias as $categoria) {

            if (count(explode('/', $categoria->href)) == 3) continue;

            $start = 1;
            do {

                $response         = $this->client->get(env('SUBMARINO_URL') . sprintf("/categoria/acessorios-de-informatica/equipamento-de-rede-wireless/pagina-%s", $start));
                $pagina_categoria = new Crawler($response->getBody()->getContents());
//                $html->filter('.product-grid-item');

                $produtos = [];
                $pagina_categoria->filter('.product-grid-item')->each(function (Crawler $node, $i) use (&$produtos) {
                    $produtos[] = $node->filter('a')->attr('href');
                });

                foreach ($produtos as $produto) {


                    $response       = $this->client->get(env('SUBMARINO_URL') . $produto);
                    $pagina_produto = new Crawler($response->getBody()->getContents());
                    $this->info(sprintf('-= Download Produto %s =-', $produto));
                    $attributes = [];
                    $pagina_produto->filter('#info-section table tbody tr')->each(function (Crawler $node, $i) use (&$attributes) {
                        $prefix = str_slug($node->filter('td:first-child span')->text(), '_');
                        if (!in_array($prefix, ['sac'])) {
                            $attributes[$prefix] = $node->filter('td:nth-child(2) span')->text();
                        }
                    });

                    $images = [];
                    $pagina_produto->filter('.image-gallery-slides source')->each(function (Crawler $node, $i) use (&$images) {
                        $images[] = $node->attr('srcset');
                    });


                    $data_produto = [
                        'code'        => explode(',', $attributes['codigo'])[0],
                        'barcode'     => (int)trim(explode(',', $attributes['codigo_de_barras'])[0]),
                        'name'        => $pagina_produto->filter("#product-name-default")->text(),
                        'href'        => env('SUBMARINO_URL') . $produto,
                        'price'       => str_replace(',', '.', str_replace(['R$', '.'], ['', ''], $pagina_produto->filter(".sales-price")->text())),
                        'description' => strip_tags($pagina_produto->filter(".info-description-frame-inside")->html()),
                        'attributes'  => $attributes,
                        'images'      => $images
                    ];

                    $product = Product::updateOrCreate(['code' => $attributes['codigo']], $data_produto);


//                    foreach ($attributes as $key => $attribute) {
//                        $attribut = ['name' => $key, 'value' => $attribute];
//                        $product->attributes()->updateOrCreate($attribut, $attribut);
//                    }
                    sleep(5);

                }
                $start++;
            } while (!empty($pagina_categoria->filter('#root')->html()));

        }
//        $categorias = [];
//        $html->filter('.sitemap-list li')->each(function (Crawler $node, $i) use (&$categorias) {
//            $categorias[] = [
//                'href' => $node->filter('a')->attr('href'),
//                'name' => $node->filter('a')->text()
//            ];
//        });
//
//        foreach ($categorias as $categoria) {
//            Category::updateOrCreate($categoria, $categoria);
//        }
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function getProdctSpecific()
    {

        $produtos = [];

        foreach ($this->produtos as $produto) {

            try {
                $response = $this->client->post($produto, [
                    'cookies' => $this->cookie
                ]);

                $html = new Crawler($response->getBody()->getContents());

                $tab_descricao = explode('<hr>', $html->filter('#tab-description')->html())[0];

                $items        = explode('<strong>', $tab_descricao);
                $data_product = [];

                try {
                    $price                 = trim($html->filter('#content .col-sm-4 .list-unstyled h2')->text());
                    $data_product['price'] = str_replace(',', '.', str_replace(['R$', '.'], ['', ''], $price));
                } catch (\InvalidArgumentException $e) {
                    continue;
                }

                $data_product['url'] = $produto;

                if (preg_match('/iveco|volvo|scania/', $produto, $matches)) {
                    $data_product['marca_caminhao'] = $matches[0];
                }
                foreach ($items as $item) {
                    if (empty($item)) {
                        continue;
                    }

                    $item = trim(str_replace([
                        'Descrição: ',
                        'Marca: ',
                        'Código interno: ',
                        'Código Fabricante: ',
                        'Código de barras: ',
                        'Ultima alteração: '
                    ], [
                        'name',
                        'marca',
                        'codigo_interno',
                        'codigo_fabricante',
                        'codigo_barra',
                        'ultima_alteracao'
                    ], $item));

                    [$title, $valor] = explode('</strong>', $item);

                    $value                = trim(strip_tags($valor));
                    $data_product[$title] = $value;
                    if (isset($data_product['ultima_alteracao'])) {
                        $data_product['ultima_alteracao'] = Carbon::createFromFormat('d/m/Y', $data_product['ultima_alteracao'])->toDateString();
                    }
                }

                $produto = ProdutoVolvoSpart::updateOrCreate(
                    ['codigo_interno' => $data_product['codigo_interno']],
                    $data_product
                );

//                $produto->history()->create(['price' => $data_product['price'], 'date' => now()->toDateString()]);
                $this->info(sprintf('-= Realizado Download Produto :%s =-', $data_product['name']));


            } catch (\Exception $exception) {
                dump($exception->getMessage(), $data_product);
            }
        }

        return $produtos;

    }


    /**
     * Define the command's schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     *
     * @return void
     */
    public function schedule(Schedule $schedule): void
    {
        $schedule->command(static::class)->dailyAt('07:40');
    }
}
