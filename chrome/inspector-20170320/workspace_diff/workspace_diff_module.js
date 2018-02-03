WorkspaceDiff.WorkspaceDiff=class{constructor(){this._uiSourceCodeDiffs=new WeakMap();}
requestDiff(uiSourceCode){return this._uiSourceCodeDiff(uiSourceCode).requestDiff();}
subscribeToDiffChange(uiSourceCode,callback,thisObj){this._uiSourceCodeDiff(uiSourceCode).on(WorkspaceDiff.DiffChangedEvent,callback,thisObj);}
unsubscribeFromDiffChange(uiSourceCode,callback,thisObj){this._uiSourceCodeDiff(uiSourceCode).off(WorkspaceDiff.DiffChangedEvent,callback,thisObj);}
_uiSourceCodeDiff(uiSourceCode){if(!this._uiSourceCodeDiffs.has(uiSourceCode))
this._uiSourceCodeDiffs.set(uiSourceCode,new WorkspaceDiff.WorkspaceDiff.UISourceCodeDiff(uiSourceCode));return this._uiSourceCodeDiffs.get(uiSourceCode);}};WorkspaceDiff.WorkspaceDiff.UISourceCodeDiff=class extends Common.Object{constructor(uiSourceCode){super();this._uiSourceCode=uiSourceCode;uiSourceCode.addEventListener(Workspace.UISourceCode.Events.WorkingCopyChanged,this._uiSourceCodeChanged,this);uiSourceCode.addEventListener(Workspace.UISourceCode.Events.WorkingCopyCommitted,this._uiSourceCodeChanged,this);this._requestDiffPromise=null;this._pendingChanges=null;}
_uiSourceCodeChanged(){if(this._pendingChanges){clearTimeout(this._pendingChanges);this._pendingChanges=null;}
this._requestDiffPromise=null;var content=this._uiSourceCode.content();var delay=(!content||content.length<65536)?0:WorkspaceDiff.WorkspaceDiff.UpdateTimeout;this._pendingChanges=setTimeout(emitDiffChanged.bind(this),delay);function emitDiffChanged(){this.emit(new WorkspaceDiff.DiffChangedEvent());this._pendingChanges=null;}}
requestDiff(){if(!this._requestDiffPromise)
this._requestDiffPromise=this._innerRequestDiff();return this._requestDiffPromise;}
async _innerRequestDiff(){var current=this._uiSourceCode.workingCopy();if(!current&&!this._uiSourceCode.contentLoaded())
current=await this._uiSourceCode.requestContent();var baseline=await this._uiSourceCode.requestOriginalContent();if(current===null||baseline===null)
return null;return Diff.Diff.lineDiff(baseline.split('\n'),current.split('\n'));}};WorkspaceDiff.DiffChangedEvent=class{};WorkspaceDiff.workspaceDiff=function(){if(!WorkspaceDiff.WorkspaceDiff._instance)
WorkspaceDiff.WorkspaceDiff._instance=new WorkspaceDiff.WorkspaceDiff();return WorkspaceDiff.WorkspaceDiff._instance;};WorkspaceDiff.WorkspaceDiff.UpdateTimeout=200;;