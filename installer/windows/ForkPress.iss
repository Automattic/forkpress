#define AppName "ForkPress"
#ifndef SourceDir
#define SourceDir "."
#endif
#ifndef AppVersion
#define AppVersion "0.1.25"
#endif

[Setup]
AppId={{7E38BFD2-1426-4C58-A541-9C76E4379E03}
AppName={#AppName}
AppVersion={#AppVersion}
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
DefaultDirName={autopf}\ForkPress
DefaultGroupName=ForkPress
DisableProgramGroupPage=yes
OutputBaseFilename=ForkPressSetup
PrivilegesRequired=admin
UninstallDisplayIcon={app}\bin\forkpress.exe
WizardStyle=modern

[Files]
Source: "{#SourceDir}\forkpress.exe"; DestDir: "{app}\bin"; Flags: ignoreversion
Source: "{#SourceDir}\vendor\vc_redist.x64.exe"; DestDir: "{app}\vendor"; Flags: ignoreversion
Source: "{#SourceDir}\scripts\windows\install.ps1"; DestDir: "{app}\scripts\windows"; Flags: ignoreversion
Source: "{#SourceDir}\scripts\windows\setup-dev-drive.ps1"; DestDir: "{app}\scripts\windows"; Flags: ignoreversion
Source: "{#SourceDir}\README-WINDOWS.md"; DestDir: "{app}"; Flags: ignoreversion

[Code]
procedure CurStepChanged(CurStep: TSetupStep);
var
  ResultCode: Integer;
  PowerShell: String;
  Parameters: String;
begin
  if CurStep <> ssPostInstall then
    Exit;

  PowerShell := ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe');
  Parameters :=
    '-NoProfile -ExecutionPolicy Bypass -File "' +
    ExpandConstant('{app}\scripts\windows\install.ps1') +
    '" -SourceRoot "' + ExpandConstant('{app}') +
    '" -InstallRoot "' + ExpandConstant('{app}') + '"';

  WizardForm.StatusLabel.Caption := 'Checking Windows prerequisites and preparing ForkPress storage...';
  if not Exec(PowerShell, Parameters, '', SW_SHOW, ewWaitUntilTerminated, ResultCode) then
  begin
    MsgBox('ForkPress setup could not start. Run ForkPressSetup.exe again. If the PowerShell setup window opened, read the red error there before closing it. Logs are written under ' + ExpandConstant('{app}\Logs') + '.', mbError, MB_OK);
    Abort;
  end;

  if ResultCode <> 0 then
  begin
    MsgBox('ForkPress setup stopped before it finished. The PowerShell setup window shows the failing prerequisite or command in red and waits for Enter before closing. Logs are written under ' + ExpandConstant('{app}\Logs') + '.', mbError, MB_OK);
    Abort;
  end;
end;
