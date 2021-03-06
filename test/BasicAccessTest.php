<?php
/**
 * @see       https://github.com/zendframework/zend-expressive-authentication-basic for the canonical source repository
 * @copyright Copyright (c) 2017-2018 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive-authentication-basic/blob/master/LICENSE.md
 *     New BSD License
 */

namespace ZendTest\Expressive\Authentication\Basic;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Expressive\Authentication\AuthenticationInterface;
use Zend\Expressive\Authentication\Basic\BasicAccess;
use Zend\Expressive\Authentication\UserInterface;
use Zend\Expressive\Authentication\UserRepositoryInterface;

class BasicAccessTest extends TestCase
{
    /** @var ServerRequestInterface|ObjectProphecy */
    private $request;

    /** @var UserRepositoryInterface|ObjectProphecy */
    private $userRepository;

    /** @var UserInterface|ObjectProphecy */
    private $authenticatedUser;

    /** @var ResponseInterface|ObjectProphecy */
    private $responsePrototype;

    /** @var callable */
    private $responseFactory;

    protected function setUp()
    {
        $this->request = $this->prophesize(ServerRequestInterface::class);
        $this->userRepository = $this->prophesize(UserRepositoryInterface::class);
        $this->authenticatedUser = $this->prophesize(UserInterface::class);
        $this->responsePrototype = $this->prophesize(ResponseInterface::class);
        $this->responseFactory = function () {
            return $this->responsePrototype->reveal();
        };
    }

    public function testConstructor()
    {
        $basicAccess = new BasicAccess(
            $this->userRepository->reveal(),
            'test',
            $this->responseFactory
        );
        $this->assertInstanceOf(AuthenticationInterface::class, $basicAccess);
    }


    /**
     * @param array $authHeader
     * @dataProvider provideInvalidAuthenticationHeader
     */
    public function testIsAuthenticatedWithInvalidData(array $authHeader)
    {
        $this->request
            ->getHeader('Authorization')
            ->willReturn($authHeader);

        $this->userRepository->authenticate(Argument::any(), Argument::any())->shouldNotBeCalled();

        $basicAccess = new BasicAccess(
            $this->userRepository->reveal(),
            'test',
            $this->responseFactory
        );
        $this->assertNull($basicAccess->authenticate($this->request->reveal()));
    }

    /**
     * @param string $username
     * @param string $password
     * @param array $authHeader
     * @dataProvider provideValidAuthentication
     */
    public function testIsAuthenticatedWithValidCredential(string $username, string $password, array $authHeader)
    {
        $this->request
            ->getHeader('Authorization')
            ->willReturn($authHeader);
        $this->request
            ->withAttribute(UserInterface::class, Argument::type(UserInterface::class))
            ->willReturn($this->request->reveal());

        $this->authenticatedUser
            ->getIdentity()
            ->willReturn($username);
        $this->userRepository
            ->authenticate($username, $password)
            ->willReturn($this->authenticatedUser->reveal());

        $basicAccess = new BasicAccess(
            $this->userRepository->reveal(),
            'test',
            $this->responseFactory
        );

        $user = $basicAccess->authenticate($this->request->reveal());
        $this->assertInstanceOf(UserInterface::class, $user);
    }

    public function testIsAuthenticatedWithNoCredential()
    {
        $this->request
            ->getHeader('Authorization')
            ->willReturn(['Basic QWxhZGRpbjpPcGVuU2VzYW1l']);

        $this->userRepository
            ->authenticate('Aladdin', 'OpenSesame')
            ->willReturn(null);

        $basicAccess = new BasicAccess(
            $this->userRepository->reveal(),
            'test',
            $this->responseFactory
        );

        $this->assertNull($basicAccess->authenticate($this->request->reveal()));
    }

    public function testGetUnauthenticatedResponse()
    {
        $this->responsePrototype
            ->getHeader('WWW-Authenticate')
            ->willReturn(['Basic realm="test"']);
        $this->responsePrototype
            ->withHeader('WWW-Authenticate', 'Basic realm="test"')
            ->willReturn($this->responsePrototype->reveal());
        $this->responsePrototype
            ->withStatus(401)
            ->willReturn($this->responsePrototype->reveal());

        $basicAccess = new BasicAccess(
            $this->userRepository->reveal(),
            'test',
            $this->responseFactory
        );

        $response = $basicAccess->unauthorizedResponse($this->request->reveal());

        $this->assertEquals(['Basic realm="test"'], $response->getHeader('WWW-Authenticate'));
    }

    public function provideInvalidAuthenticationHeader(): array
    {
        return [
            'empty-header' => [[]],
            'missing-basic-prefix' => [['foo']],
            'only-username-without-colon' => [['Basic ' . base64_encode('Aladdin')]],
            'base64-encoded-pile-of-poo-emoji' => [['Basic ' . base64_encode('💩')]],
            'pile-of-poo-emoji' => [['Basic 💩']],
            'only-pile-of-poo-emoji' => [['💩']],
            'basic-prefix-without-content' => [['Basic ']],
            'only-basic' => [['Basic']],
            'multiple-auth-headers' => [
                [
                    ['Basic ' . base64_encode('Aladdin:OpenSesame')],
                    ['Basic ' . base64_encode('Aladdin:OpenSesame')],
                ],
            ],
        ];
    }

    public function provideValidAuthentication(): array
    {
        return [
            'aladdin' => ['Aladdin', 'OpenSesame', ['Basic ' . base64_encode('Aladdin:OpenSesame')]],
            'aladdin-with-nonzero-array-index' => [
                'Aladdin',
                'OpenSesame',
                [-200 => 'Basic ' . base64_encode('Aladdin:OpenSesame')]
            ],
            'passwords-with-colon' => ['Aladdin', 'Open:Sesame', ['Basic ' . base64_encode('Aladdin:Open:Sesame')]],
            'username-without-password' => ['Aladdin', '', ['Basic ' . base64_encode('Aladdin:')]],
            'password-without-username' => ['', 'OpenSesame', ['Basic ' . base64_encode(':OpenSesame')]],
            'passwords-with-multiple-colons' => [
                'Aladdin',
                '::Open:::Sesame::',
                ['Basic ' . base64_encode('Aladdin:::Open:::Sesame::')]
            ],
            'no-username-or-password' => ['', '', ['Basic ' . base64_encode(':')]],
            'no-username-password-only-colons' => ['', '::::::', ['Basic ' . base64_encode(':::::::')]],
            'unicode-username-and-password' => [
                'thumbsup-emoji-👍',
                'thumbsdown-emoji-👎',
                ['Basic ' . base64_encode('thumbsup-emoji-👍:thumbsdown-emoji-👎')]],
        ];
    }
}
