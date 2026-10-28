# Subplugins de fonte de vídeo

O tipo `videoprogresssource` permite adicionar uma nova origem de vídeo sem alterar o módulo principal. Cada subplugin
fica em `mod/videoprogress/source/<nome>` e usa o componente `videoprogresssource_<nome>`.

## Estrutura mínima

```text
source/example/
├── amd/
│   ├── build/player.js
│   ├── build/player.min.js
│   └── src/player.js
├── classes/
│   ├── plugin.php
│   └── privacy/provider.php
├── lang/
│   ├── en/videoprogresssource_example.php
│   └── pt_br/videoprogresssource_example.php
├── templates/player.mustache
└── version.php
```

`classes/plugin.php` deve declarar `videoprogresssource_example\plugin` e
estender `mod_videoprogress\source\plugin_base`. A classe informa:

- nome localizado;
- ordem opcional no seletor e preferência padrão;
- campos e regras de visibilidade do formulário;
- validação e normalização da configuração;
- valor de compatibilidade para backups antigos;
- configuração segura enviada ao navegador;
- template Mustache;
- módulo AMD;
- recursos opcionais de poster, legendas, transcrição e File API.

O módulo AMD deve retornar um objeto com o método `create(root, config)`. A Promise resolvida deve fornecer o contrato
comum:

```text
play()
pause()
getCurrentTime()
getDuration()
getPlaybackRate()
seek(position)
onPlay(handler)
onPause(handler)
onTimeUpdate(handler)
onSeek(handler)
onEnded(handler)
onRateChange(handler)
```

O subplugin deve calcular somente eventos e dados brutos do player. O percentual, os segmentos válidos, o anti-skip, a
conclusão e a nota continuam sendo calculados pelo servidor no módulo principal.

Configurações administrativas opcionais podem ser declaradas em `settings.php`. Arquivos próprios devem usar a File API
do componente do subplugin e implementar o respectivo callback `pluginfile` quando necessário.

Toda classe e todo método novo deve possuir PHPDoc ou JSDoc em inglês explicando sua responsabilidade.
