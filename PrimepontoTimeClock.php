<?php

namespace app\timeclock\integrations;

use app\errors\BadRequestException;
use app\errors\InternalErrorException;
use Carbon\Carbon;
use Lean\Format\Date;
use Lean\Format\Time;
use app\timeclock\models\bo\TimeClock;
use app\api\models\bo\User;
use app\api\models\bo\Config;

class PrimepontoTimeClock
{
	private $client = null;

	private $cnpj_primary = null;

    private $cnpj_secondary = null;

	/**
	 * @throws BadRequestException
	 * @throws InternalErrorException
	 */
	public function __construct()
	{
		$this->initialize_client();

        $this->cnpj_primary = Config::get([ 'name' => 'integration_time_clock_primeponto_cnpj_primary' ])->value;

        $this->cnpj_secondary = Config::get([ 'name' => 'integration_time_clock_primeponto_cnpj_secondary' ])->value;

        if (empty($this->cnpj_primary))
            throw new BadRequestException('O CNPJ para integração com Primeponto não foi cadastrado');
	}

	/**
	 * Inicializa cliente Soap de conexão ao WebService do Logponto
	 */
	private function initialize_client()
	{
        try {

			ini_set("soap.wsdl_cache_enabled", 0);

            $primeponto_login = Config::get([ 'name' => 'integration_time_clock_primeponto_login' ])->value;

            if (empty($primeponto_login))
                throw new BadRequestException('O Login para integração com Primeponto não foi cadastrado');

            $primeponto_password = Config::get([ 'name' => 'integration_time_clock_primeponto_password' ])->value;

            if (empty($primeponto_password))
                throw new BadRequestException('A senha para integração com Primeponto não foi cadastrado');

            $primeponto_contexto = Config::get([ 'name' => 'integration_time_clock_primeponto_contexto' ])->value;

            if (empty($primeponto_password))
                throw new BadRequestException('O contexto do gateway para integração com Primeponto não foi cadastrado');

            $this->client = new \SoapClient("https://srep.primesw.com.br/{$primeponto_contexto}_gateway/folha?WSDL",
                [
                    'location' => "https://srep.primesw.com.br/{$primeponto_contexto}_gateway/folha?WSDL",
                    'login' => $primeponto_login,
                    'password' => $primeponto_password,
                    'trace' => 1,
                    "exceptions" => 1,
                    "cache_wsdl" => 0
                 ]
            );

		} catch (\SoapFault $e) {
            throw new InternalErrorException('Não foi possível iniciar a integração', $e);
        }
	}

	/**
	 * Importa horas do Primeponto por mês ou dia
	 * @param Carbon $date_start
	 * @param Carbon $date_end
	 * @return bool
	 * @throws BadRequestException
	 * @throws InternalErrorException
	 */
    public function import(Carbon $date_start, Carbon $date_end)
	{
        /** verifica se a data é posterior a data de inicio de uso da integração pelo cliente */
        $primeponto_allowed_after_date = Config::get([ 'name' => 'integration_time_clock_primeponto_allowed_after_date' ])->value;

        if (!empty($primeponto_allowed_after_date)) {

            if (!Date::validate($primeponto_allowed_after_date))
                throw new BadRequestException('A data a partir de informada para integração com Primeponto é inválida');

            if ($date_start < Carbon::createFromFormat('d/m/Y', $primeponto_allowed_after_date))
                throw new BadRequestException('A data de início informada é inferior a data permitida para integração com Primeponto');

        }

        $date_current = clone $date_start;

        while ($date_current <= $date_end) {
            TimeClock::delete([ 'date' => $date_current->toDateString() ]);
            $date_current->addDay();
        }

        /** importa os registros do dia ou mês */
        $this->import_by_folha_ponto($date_start, $date_end);

        return true;
	}

	/**
     * Importar horas de registros de ponto
     * registros de ponto traz o horário de cada batida de ponto do colaborador
     * através do qual é possível calcular o intervalo de tempo entre entradas e saídas
	 * @param Carbon $date_start
	 * @param Carbon $date_end
	 * @throws BadRequestException
	 * @throws InternalErrorException
	 */
	public function import_by_folha_ponto(Carbon $date_start, Carbon $date_end)
	{
		/**
		 * busca horas somente de usuário cadastrado com cpf no sistema
		 * no primeponto cada usuário é identificado pelo cpf
		 */
		$users = User::fetch([ 'has_cpf' => true ]);

        if (!$users)
            throw new BadRequestException('Cadastre o CPF dos usuários que deseja fazer a sincronização com Primeponto');

		foreach ($users as $user)
		{
            /**
             * @param Carbon $date_start
             * @param Carbon $date_end
             * @param $cpf
             * @param $cnpj
             */
            $call_folha_ponto = function(Carbon $date_start, Carbon $date_end, $cpf, $cnpj) {

                /**
                 * Lista os registros de ponto por cnpj, cpf e período
                 *
                 * @return
                 * <soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
                 *      <soap:Body>
                 *          <ns2:folhaPontoResponse xmlns:ns2="http://folha.primews.com.br/">
                 *              <return>
                 *                  <cnpj>14.425.578/0001-02</cnpj>
                 *                  <pis>12883990532</pis>
                 *                  <itens>
                 *                      <data>2015-07-01T00:00:00-03:00</data>
                 *                      <intervalos>
                 *                          <dataHora>2015-07-01T09:02:00-03:00</dataHora>
                 *                          <rep/>
                 *                          <tipo>MarcacaoGeoMobi</tipo>
                 *                          <justificativa/>
                 *                      </intervalos>
                 *                      <intervalos>
                 *                          <dataHora>2015-07-01T13:01:00-03:00</dataHora>
                 *                          <rep/>
                 *                          <tipo>MarcacaoGeoMobi</tipo>
                 *                          <justificativa/>
                 *                      </intervalos>
                 *                  </itens>
                 *              </return>
                 *          </ns2:folhaPontoResponse>
                 *      </soap:Body>
                 * </soap:Envelope>
                 */
                $this->client->folhaPonto(
                    array(
                        'filter' => array(
                            'dataHoraInicio' => $date_start->toDateString(),
                            'dataHoraTermino' => $date_end->toDateString(),
                            'cpf' => $cpf,
                            'cnpj' => $cnpj
                        )
                    )
                );

            };

            /** Busca registros por CNPJ primário e secundário */
            try {
                try {
                    $call_folha_ponto($date_start, $date_end, $user->cpf, $this->cnpj_primary);
                } catch (\SoapFault $e) {
                    if ($e->faultstring == 'Falha ao executar consulta: Funcionário não possui contrato neste CNPJ' && $this->cnpj_secondary) {
                        $call_folha_ponto($date_start, $date_end, $user->cpf, $this->cnpj_secondary);
                    } else {
                        throw $e;
                    }
                }
            } catch (\SoapFault $e) {
                continue 1;
            }

            $folhaPonto_response = $this->client->__getLastResponse();

            if (!$folhaPonto_response)
                throw new InternalErrorException('Ocorreu uma falha desconhecida ao buscar registros de folha ponto do Primeponto', $e);

			$folhaPonto_return = simplexml_load_string($folhaPonto_response);

            $date_current = clone $date_start;

            while ($date_current <= $date_end) {

                /** Buscar htr (horas trabalhadas reias) no node itens */
                $itens = $folhaPonto_return->xpath("//itens[data[starts-with(., '". $date_current->toDateString() ."')]]");
                $time_total = Time::seconds_to_time($itens[0]->htr * 60);

				TimeClock::save([
                    'date' => $date_current->toDateString(),
                    'user_id' => $user->id,
                    'time' => $time_total
                 ]);

                $date_current->addDay();

			}
		}
	}
}