<?php

namespace App\Command;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Server;
use Amp\Http\Status;
use Amp\Loop;
use App\Handler\RequestHandler;
use App\Routing\AnnotatedRouteControllerLoader;
use Doctrine\Common\Annotations\AnnotationReader;
use Psr\Log\NullLogger;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\Loader\AnnotationDirectoryLoader;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use function Amp\Socket\listen;

final class ServerStartCommand extends Command
{
    protected static $defaultName = 'server:start';

    protected function configure()
    {
        $this
            ->setDescription("Start the Http server")
            ->addOption("port", "p", InputOption::VALUE_REQUIRED, "The port to be listen", 1337)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $loader = new AnnotationDirectoryLoader(
            new FileLocator(__DIR__.'/../src/Action/'),
            new AnnotatedRouteControllerLoader(new AnnotationReader())
        );

        $routes = $loader->load(__DIR__.'/../Action/');
        $context = new RequestContext('/');
        $router = new UrlMatcher($routes, $context);

        $status = new ProgressBar($output);
        $status->setFormat("Since: <info>%elapsed%</info> | Memory: <info>%memory%</info>  | Requests:  <info>%current%</info>");
        $status->start();

        Loop::run(function() use ($input, $status, $router) {
            $sockets = [
                listen(sprintf("0.0.0.0:%d", $input->getOption("port"))),
                listen(sprintf("[::]:%d", $input->getOption("port"))),
            ];

            $server = new Server($sockets, new RequestHandler($router, $status), new NullLogger());

            yield $server->start();

            Loop::onSignal(SIGINT, function (string $watcherId) use ($server) {
                Loop::cancel($watcherId);
                yield $server->stop();
            });

            Loop::repeat(1000, function() use ($status) {
                $status->display();
            });
        });

        $status->finish();

        return 0;
    }
}
