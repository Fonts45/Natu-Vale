# Natu Vale — landing page

## Estrutura
- `index.html`: página principal (CSS e JavaScript permanecem nela).
- `assets/imagens/`: fotos, logos e tabelas.
- `assets/icones/`: ícones das etapas de produção.
- `.nojekyll`: permite publicar os arquivos estáticos diretamente.

## Publicar no GitHub Pages
1. No repositório `fonts45/Natu-Vale`, escolha **Add file → Upload files**.
2. Abra esta pasta no Explorador do Windows. Arraste **index.html, a pasta assets inteira, README.md e .nojekyll** para o GitHub. Não abra assets para arrastar apenas os arquivos de dentro.
3. Confirme antes de salvar que os arquivos aparecem como `assets/imagens/...` e `assets/icones/...` e que `index.html` está na raiz.
4. Salve o commit na branch usada pelo Pages. Em Settings → Pages, a pasta de publicação deve ser `/(root)` quando esta estrutura estiver na raiz.
5. Aguarde o deployment terminar e abra https://fonts45.github.io/Natu-Vale/.

O GitHub Pages reconhece subpastas normalmente. Mantenha exatamente os nomes e caminhos. Se usar um ZIP, extraia-o primeiro: enviar somente o ZIP não publica o site.

Para abrir localmente, abra index.html mantendo assets ao lado dele.
